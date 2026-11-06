<?php

namespace App\Ai;

use App\Enums\AiVendor;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Fetches the model names a vendor offers, for suggestions on the provider
 * form. laravel/ai has no model listing, so this is plain HTTP.
 */
class ProviderModels
{
    private const CACHE_SECONDS = 60;

    private const TIMEOUT_SECONDS = 5;

    /**
     * @return list<string>|null sorted model names, an empty list when the
     *                           needed credential is missing, null when the fetch failed
     */
    public static function for(AiVendor $vendor, ?string $key, ?string $url): ?array
    {
        $key = trim((string) $key);
        $url = rtrim(trim((string) $url), '/');

        if ($vendor === AiVendor::Ollama ? $url === '' : $key === '') {
            return [];
        }

        $cacheKey = 'ai-provider-models:'.hash('sha256', implode("\0", [$vendor->value, $url, $key]));

        /** @var array{models: list<string>|null} $outcome */
        $outcome = Cache::remember(
            $cacheKey,
            self::CACHE_SECONDS,
            fn (): array => ['models' => self::fetch($vendor, $key, $url)],
        );

        return $outcome['models'];
    }

    /**
     * @return list<string>|null
     */
    private static function fetch(AiVendor $vendor, string $key, string $url): ?array
    {
        try {
            $response = match ($vendor) {
                AiVendor::Ollama => Http::timeout(self::TIMEOUT_SECONDS)->get($url.'/api/tags'),
                AiVendor::Anthropic => Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                    ->get(self::baseUrl($vendor).'/models', ['limit' => 1000]),
                AiVendor::OpenAi, AiVendor::Mistral => Http::timeout(self::TIMEOUT_SECONDS)
                    ->withToken($key)
                    ->get(self::baseUrl($vendor).'/models'),
            };

            $response->throw();
        } catch (ConnectionException|RequestException) {
            return null;
        }

        $json = $response->json();
        $entries = is_array($json) ? ($vendor === AiVendor::Ollama ? ($json['models'] ?? null) : ($json['data'] ?? null)) : null;

        if (! is_array($entries)) {
            return null;
        }

        $field = $vendor === AiVendor::Ollama ? 'name' : 'id';
        $names = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry[$field] ?? null) && $entry[$field] !== '') {
                $names[] = $entry[$field];
            }
        }

        sort($names);

        return $names;
    }

    private static function baseUrl(AiVendor $vendor): string
    {
        $configured = config("ai.providers.{$vendor->value}.url");

        if (is_string($configured) && $configured !== '') {
            return rtrim($configured, '/');
        }

        return $vendor === AiVendor::Mistral ? 'https://api.mistral.ai/v1' : '';
    }
}
