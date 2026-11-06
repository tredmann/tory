<?php

namespace App\Models\Concerns;

use App\Enums\ProviderFailureReason;
use Illuminate\Support\Carbon;

/**
 * Running state and last failure of "Neu generieren" on a record with photos.
 *
 * @property Carbon|null $regenerating_at
 * @property string|null $regeneration_error
 * @property ProviderFailureReason|null $regeneration_failure_reason
 * @property string|null $regeneration_failure_provider
 */
trait RegeneratesTexts
{
    public function initializeRegeneratesTexts(): void
    {
        $this->mergeCasts([
            'regenerating_at' => 'datetime',
            'regeneration_failure_reason' => ProviderFailureReason::class,
        ]);
    }

    /**
     * A model created in this request holds null until reloaded, which counts as not running.
     */
    public function isRegenerating(): bool
    {
        return $this->regenerating_at !== null;
    }

    /**
     * Marks the record as regenerating. Atomic: only one caller wins while
     * a run is active. Fires no model events.
     */
    public function startRegeneration(): bool
    {
        $now = now();

        $changed = static::query()
            ->whereKey($this->getKey())
            ->whereNull('regenerating_at')
            ->update([
                'regenerating_at' => $now,
                'regeneration_error' => null,
                'regeneration_failure_reason' => null,
                'regeneration_failure_provider' => null,
            ]);

        if ($changed !== 1) {
            return false;
        }

        $this->forceFill([
            'regenerating_at' => $now,
            'regeneration_error' => null,
            'regeneration_failure_reason' => null,
            'regeneration_failure_provider' => null,
        ])->syncOriginal();

        return true;
    }

    /**
     * The fixed sentence for a failure the user can fix on "Eigene KI".
     */
    public function regenerationFailureMessage(): ?string
    {
        return $this->regeneration_failure_reason?->message($this->regeneration_failure_provider ?? 'Der KI-Provider');
    }
}
