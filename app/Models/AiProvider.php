<?php

namespace App\Models;

use App\Enums\AiVendor;
use Database\Factories\AiProviderFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\Provider;

/**
 * @property AiVendor $vendor
 */
class AiProvider extends Model
{
    /** @use HasFactory<AiProviderFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'vendor',
        'api_key',
        'url',
        'model',
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
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $provider): void {
            if ($provider->isDirty('api_key')) {
                $key = $provider->readableKey();
                $provider->key_hint = $key === null ? null : substr($key, -4);
            }
        });

        static::deleting(fn (self $provider): bool => ! $provider->is_active);
    }

    public static function active(): ?self
    {
        return static::query()->where('is_active', true)->first();
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
