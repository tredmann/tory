<?php

namespace App\Models;

use Database\Factories\SpotFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Random\Randomizer;

class Spot extends Model
{
    /** @use HasFactory<SpotFactory> */
    use HasFactory;

    /**
     * Digits and uppercase letters without the look-alikes 0, O, 1 and I.
     */
    public const string CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const int CODE_LENGTH = 4;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'note',
        'location_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Spot $spot): void {
            $spot->code ??= self::generateCode();
        });
    }

    public static function generateCode(): string
    {
        $randomizer = new Randomizer;

        do {
            $code = $randomizer->getBytesFromString(self::CODE_ALPHABET, self::CODE_LENGTH);
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * @return Attribute<non-falsy-string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->code} · {$this->name}");
    }

    /**
     * @return Attribute<non-falsy-string, never>
     */
    protected function fullLabel(): Attribute
    {
        return Attribute::get(fn (): string => "{$this->location->name} → {$this->label}");
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    /**
     * @return HasMany<Import, $this>
     */
    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
