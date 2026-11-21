<?php

namespace App\Models;

use App\Ai\ProviderFailure;
use App\Enums\AiVendor;
use App\Enums\ProviderFailureReason;
use Database\Factories\AiProviderFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\Provider;

/**
 * @property string $id
 * @property AiVendor $vendor
 * @property string|null $model
 * @property string|null $embedding_model
 * @property ProviderFailureReason|null $search_failure_reason
 */
class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory;

    use HasUuids;

    public const float DEFAULT_SEARCH_MIN_SIMILARITY = 0.5;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'vendor',
        'api_key',
        'url',
        'model',
        'embedding_model',
        'search_min_similarity',
    ];

    /** @var list<string> */
    protected $hidden = [
        'api_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'vendor' => AiVendor::class,
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
            'is_active_for_search' => 'boolean',
            'search_min_similarity' => 'float',
            'search_failure_reason' => ProviderFailureReason::class,
            'search_failure_generic' => 'boolean',
        ];
    }

    /**
     * The columns that record why search embedding failed, or clear it. The
     * text is built when shown (searchFailureMessage()), in the viewer's language.
     *
     * @return array{search_failure: null, search_failure_reason: ?string, search_failure_dimensions: ?int, search_failure_generic: bool}
     */
    public static function searchFailureColumns(bool $failed, ?ProviderFailure $failure = null): array
    {
        return [
            'search_failure' => null,
            'search_failure_reason' => $failed ? $failure?->reason?->value : null,
            'search_failure_dimensions' => $failed ? $failure?->dimensions : null,
            'search_failure_generic' => $failed && ($failure?->userMessage() === null),
        ];
    }

    /**
     * The failure text for the viewer: built from the stored parts, else the
     * free text of a row written before they existed.
     */
    public function searchFailureMessage(): ?string
    {
        if ($this->search_failure_dimensions !== null) {
            return ProviderFailure::dimensionsMessage($this->name, $this->search_failure_dimensions);
        }

        if ($this->search_failure_reason !== null) {
            return $this->search_failure_reason->message($this->name);
        }

        if ($this->search_failure_generic) {
            return ProviderFailure::genericEmbeddingMessage();
        }

        return filled($this->search_failure) ? $this->search_failure : null;
    }

    protected static function booted(): void
    {
        static::saving(function (self $provider): void {
            if (($provider->getAttributes()['api_key'] ?? null) !== $provider->getRawOriginal('api_key')) {
                $key = $provider->readableKey();
                $provider->key_hint = $key === null ? null : substr($key, -4);
            }
        });

        static::deleting(fn (self $provider): bool => ! static::query()
            ->whereKey($provider->getKey())
            ->where(fn ($query) => $query->where('is_active', true)->orWhere('is_active_for_search', true))
            ->exists());
    }

    /**
     * Compares the stored key payloads raw, so saving never has to decrypt
     * an old key that has become unreadable.
     *
     * @param  string  $key
     */
    public function originalIsEquivalent($key): bool
    {
        if ($key === 'api_key') {
            return ($this->attributes[$key] ?? null) === ($this->original[$key] ?? null);
        }

        return parent::originalIsEquivalent($key);
    }

    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->first();
    }

    public static function activeForSearch(): ?self
    {
        return static::query()->where('is_active_for_search', true)->first();
    }

    /**
     * Makes this provider the only active one. Locking every row first lets a
     * concurrent activation wait; the active row is cleared before this one is
     * set because the unique index is checked per statement.
     */
    public function activate(): void
    {
        DB::transaction(function (): void {
            static::query()->lockForUpdate()->get(['id']);
            static::query()->where('is_active', true)->update(['is_active' => false]);
            static::query()->whereKey($this->getKey())->update(['is_active' => true]);
        });

        $this->is_active = true;
        $this->syncOriginalAttribute('is_active');
    }

    /**
     * Builds an on-demand laravel/ai provider from the stored settings. The
     * key is read directly, so an unreadable key surfaces when prompted.
     */
    public function toLaravelProvider(): Provider
    {
        $vendor = $this->vendor;
        /** @var array<string, mixed> $config */
        $config = config("ai.providers.{$vendor->value}", []);
        $config['key'] = $vendor === AiVendor::Ollama ? '' : $this->api_key;

        if ($vendor === AiVendor::Ollama && $this->url !== null && $this->url !== '') {
            $config['url'] = $this->url;
        }

        return Ai::build($config);
    }

    public function readableKey(): ?string
    {
        if (! $this->hasStoredKey()) {
            return null;
        }

        try {
            $key = Crypt::decryptString($this->attributes['api_key']);
        } catch (DecryptException) {
            return null;
        }

        return $key !== '' ? $key : null;
    }

    public function hasUnreadableKey(): bool
    {
        return $this->hasStoredKey() && $this->readableKey() === null;
    }

    public function scrub(string $text): string
    {
        $key = $this->readableKey();

        return $key === null ? $text : str_replace($key, '[Key]', $text);
    }

    private function hasStoredKey(): bool
    {
        $raw = $this->attributes['api_key'] ?? null;

        return is_string($raw) && $raw !== '';
    }
}
