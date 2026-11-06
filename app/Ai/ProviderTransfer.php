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
    private const FORMAT = 'tory-ai-providers';

    private const VERSION = 1;

    private const MAX_NAME_LENGTH = 255;

    /**
     * Leaves out providers whose key can no longer be decrypted, because their
     * entries could not be imported anywhere.
     *
     * @return array{document: array{format: string, version: int, providers: array<int, array<string, string|null>>}, omitted: int}
     */
    public function export(): array
    {
        $providers = AiProvider::query()->orderBy('name')->get();
        $readable = $providers->reject(fn (AiProvider $provider): bool => $provider->hasUnreadableKey());

        return [
            'document' => [
                'format' => self::FORMAT,
                'version' => self::VERSION,
                'providers' => $readable->map(fn (AiProvider $provider): array => [
                    'uuid' => $provider->uuid,
                    'name' => $provider->name,
                    'vendor' => $provider->vendor->value,
                    'api_key' => $provider->readableKey(),
                    'url' => $provider->url,
                    'model' => $provider->model,
                ])->values()->all(),
            ],
            'omitted' => $providers->count() - $readable->count(),
        ];
    }

    /**
     * Imports all entries or none. An existing uuid is skipped without any
     * change; a new uuid with a taken name is created under a free name.
     *
     * @return array{created: int, skipped: int, renamed: int}
     *
     * @throws InvalidArgumentException with a message that repeats nothing from the file
     */
    public function import(string $json): array
    {
        $entries = $this->validEntries($json);

        return DB::transaction(function () use ($entries): array {
            $result = ['created' => 0, 'skipped' => 0, 'renamed' => 0];

            foreach ($entries as $entry) {
                $uuid = strtolower($entry['uuid']);

                if (AiProvider::query()->where('uuid', $uuid)->exists()) {
                    $result['skipped']++;

                    continue;
                }

                $name = trim($entry['name']);
                $freeName = $this->freeName($name);
                $vendor = AiVendor::from($entry['vendor']);

                AiProvider::create([
                    'uuid' => $uuid,
                    'name' => $freeName,
                    'vendor' => $vendor,
                    'api_key' => $vendor->requiresKey() ? trim($entry['api_key'] ?? '') : null,
                    'url' => $vendor === AiVendor::Ollama ? rtrim(trim($entry['url'] ?? ''), '/') : null,
                    'model' => trim($entry['model']),
                ]);

                $result['created']++;

                if ($freeName !== $name) {
                    $result['renamed']++;
                }
            }

            return $result;
        });
    }

    /**
     * @return list<array{uuid: string, name: string, vendor: string, model: string, api_key?: string, url?: string}>
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

        return array_values($entries);
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
