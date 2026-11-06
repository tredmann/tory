<?php

namespace App\Ai;

use App\Enums\ProviderFailureReason;
use App\Models\AiProvider;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * A failed call (extraction or embedding) on a configured provider. The message is scrubbed and
 * the original exception is not kept as previous, because its message and
 * trace may echo the API key. A request exception's own message is cut to
 * 120 characters, which can split a key, so its full body is scrubbed first.
 */
final class ProviderFailure extends RuntimeException
{
    public const string GENERIC_EMBEDDING_MESSAGE = 'Das Embedding ist fehlgeschlagen. Bitte Modell und Zugangsdaten prüfen.';

    public function __construct(
        string $message,
        public readonly ?ProviderFailureReason $reason,
        public readonly string $providerName,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($message);
    }

    public static function from(Throwable $e, AiProvider $provider): self
    {
        $message = $e instanceof RequestException
            ? $e::class.': HTTP '.$e->response->status().': '.Str::limit($provider->scrub($e->response->body()), 200)
            : $provider->scrub($e::class.': '.$e->getMessage());

        return new self(
            $message,
            ProviderFailureReason::fromThrowable($e),
            $provider->name,
            $e instanceof WrongEmbeddingDimensions
                ? "Das Embedding-Modell von {$provider->name} liefert {$e->actual} statt ".ItemEmbedder::DIMENSIONS.' Dimensionen.'
                : null,
        );
    }

    public function userMessage(): ?string
    {
        return $this->detail ?? $this->reason?->message($this->providerName);
    }
}
