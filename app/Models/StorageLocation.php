<?php

namespace App\Models;

use Database\Factories\StorageLocationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageLocation extends Model
{
    /** @use HasFactory<StorageLocationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'note',
    ];

    /**
     * @return Attribute<non-falsy-string, never>
     */
    protected function label(): Attribute
    {
        return Attribute::get(fn (): string => "Nr. {$this->id} · {$this->name}");
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
}
