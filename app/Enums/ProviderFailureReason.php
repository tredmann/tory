<?php

namespace App\Enums;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Throwable;

/**
 * A provider failure the user can fix on the "Eigene KI" page.
 */
enum ProviderFailureReason: string
{
    case KeyRejected = 'key_rejected';
    case KeyUnreadable = 'key_unreadable';
    case UnknownModel = 'unknown_model';
    case ImagesUnsupported = 'images_unsupported';
    case Unreachable = 'unreachable';

    /**
     * Returns null for failures that cannot be recognised reliably.
     */
    public static function fromThrowable(Throwable $e): ?self
    {
        if ($e instanceof DecryptException) {
            return self::KeyUnreadable;
        }

        if ($e instanceof ProviderConnectionException
            || $e instanceof ProviderOverloadedException
            || $e instanceof ConnectionException) {
            return self::Unreachable;
        }

        if (! $e instanceof RequestException) {
            return null;
        }

        $status = $e->response->status();

        if ($status === 401 || $status === 403) {
            return self::KeyRejected;
        }

        if ($status === 404) {
            return self::UnknownModel;
        }

        if ($status !== 400 && $status !== 422) {
            return null;
        }

        $body = strtolower($e->response->body());

        if (str_contains($body, 'invalid_model') || str_contains($body, 'unknown model') || str_contains($body, 'model not found')) {
            return self::UnknownModel;
        }

        if (preg_match('/(not|n\'t|no)\s+support(ed)?\s+(for\s+)?(image|vision)|image_url is only supported by certain models/', $body) === 1) {
            return self::ImagesUnsupported;
        }

        return null;
    }

    public function message(string $providerName): string
    {
        return match ($this) {
            self::KeyRejected => "{$providerName} hat den API-Key abgelehnt.",
            self::KeyUnreadable => "Der gespeicherte API-Key von {$providerName} ist nicht mehr lesbar.",
            self::UnknownModel => "{$providerName} kennt das eingestellte Modell nicht.",
            self::ImagesUnsupported => "Das bei {$providerName} eingestellte Modell kann keine Fotos verarbeiten.",
            self::Unreachable => "{$providerName} ist nicht erreichbar.",
        };
    }
}
