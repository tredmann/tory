<?php

namespace App\Ai;

use App\Enums\ProviderFailureReason;
use App\Models\AiProvider;
use RuntimeException;
use Throwable;

/**
 * A failed extraction on a configured provider. The message is scrubbed and
 * the original exception is not kept as previous, because its message and
 * trace may echo the API key.
 */
final class ProviderFailure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?ProviderFailureReason $reason,
        public readonly string $providerName,
    ) {
        parent::__construct($message);
    }

    public static function from(Throwable $e, AiProvider $provider): self
    {
        return new self(
            $provider->scrub($e::class.': '.$e->getMessage()),
            ProviderFailureReason::fromThrowable($e),
            $provider->name,
        );
    }
}
