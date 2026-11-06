<?php

namespace App\Ai;

use App\Enums\AiVendor;
use App\Models\AiProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;

/**
 * Moves AI providers between installations as a document with plain text keys,
 * because stored keys are encrypted with each installation's own APP_KEY. The
 * active state is never part of it: an import must not redirect photos.
 */
class ProviderTransfer
{
    public const FORMAT = 'tory-ai-providers';

    public const VERSION = 1;

    private const MAX_NAME_LENGTH = 255;

    /**
     * Leaves out providers whose key can no longer be decrypted, because their
     * entries could not be imported anywhere.
     */
    public function export(): ProviderExport
    {
        $providers = AiProvider::query()->orderBy('name')->get();
        $readable = $providers->reject(fn (AiProvider $provider): bool => $provider->hasUnreadableKey());

        return new ProviderExport(
            entries: array_values($readable->map(fn (AiProvider $provider): ProviderTransferEntry => new ProviderTransferEntry(
                uuid: $provider->id,
                name: $provider->name,
                vendor: $provider->vendor,
                model: $provider->model,
                apiKey: $provider->readableKey(),
                url: $provider->url,
            ))->all()),
            omitted: $providers->count() - $readable->count(),
        );
    }

    /**
     * Imports all entries or none. An existing uuid is skipped without any
     * change; a new uuid with a taken name is created under a free name.
     *
     * @throws InvalidArgumentException with a message that repeats nothing from the file
     */
    public function import(string $json): ProviderImportResult
    {
        $entries = $this->validEntries($json);

        return DB::transaction(function () use ($entries): ProviderImportResult {
            $created = 0;
            $skipped = 0;
            $renamed = 0;

            foreach ($entries as $entry) {
                $uuid = strtolower($entry->uuid);

                if (AiProvider::query()->whereKey($uuid)->exists()) {
                    $skipped++;

                    continue;
                }

                $name = trim($entry->name);
                $freeName = $this->freeName($name);

                $provider = new AiProvider([
                    'name' => $freeName,
                    'vendor' => $entry->vendor,
                    'api_key' => $entry->vendor->requiresKey() ? trim($entry->apiKey ?? '') : null,
                    'url' => $entry->vendor === AiVendor::Ollama ? rtrim(trim($entry->url ?? ''), '/') : null,
                    'model' => trim($entry->model),
                ]);
                $provider->id = $uuid;
                $provider->save();

                $created++;

                if ($freeName !== $name) {
                    $renamed++;
                }
            }

            return new ProviderImportResult($created, $skipped, $renamed);
        });
    }

    /**
     * @return list<ProviderTransferEntry>
     */
    private function validEntries(string $json): array
    {
        try {
            $document = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Die Datei enthält kein gültiges JSON.');
        }

        $validator = Validator::make(is_array($document) ? $document : [], [
            'format' => ['required', Rule::in([self::FORMAT])],
            'version' => ['required', 'integer', Rule::in([self::VERSION])],
            'providers' => ['present', 'array', 'list'],
            'providers.*' => ['array'],
            'providers.*.uuid' => ['required', 'string', 'uuid', 'distinct:ignore_case'],
            'providers.*.name' => ['required', 'string', 'max:'.self::MAX_NAME_LENGTH],
            'providers.*.vendor' => ['required', 'string', Rule::enum(AiVendor::class)],
            'providers.*.model' => ['required', 'string', 'max:255'],
            'providers.*.api_key' => ['exclude_if:providers.*.vendor,'.AiVendor::Ollama->value, 'required', 'string'],
            'providers.*.url' => ['exclude_unless:providers.*.vendor,'.AiVendor::Ollama->value, 'required', 'string', 'url:http,https'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException($this->errorMessage((string) $validator->errors()->keys()[0]));
        }

        /** @var array<int, array{uuid: string, name: string, vendor: string, model: string, api_key?: string, url?: string}> $entries */
        $entries = $validator->validated()['providers'];

        return array_map(ProviderTransferEntry::fromValidated(...), array_values($entries));
    }

    private function errorMessage(string $field): string
    {
        if (preg_match('/^providers\.(\d+)(?:\.(\w+))?/', $field, $matches) !== 1) {
            return 'Die Datei ist keine Tory-Provider-Datei.';
        }

        $position = (int) $matches[1] + 1;

        return isset($matches[2])
            ? "Eintrag {$position}: Das Feld „{$matches[2]}“ fehlt oder ist ungültig."
            : "Eintrag {$position} ist ungültig.";
    }

    private function freeName(string $name): string
    {
        $candidate = $name;
        $attempt = 1;

        while (AiProvider::query()->where('name', $candidate)->exists()) {
            $suffix = $attempt === 1 ? ' (Import)' : " (Import {$attempt})";
            $candidate = mb_substr($name, 0, self::MAX_NAME_LENGTH - mb_strlen($suffix)).$suffix;
            $attempt++;
        }

        return $candidate;
    }
}
