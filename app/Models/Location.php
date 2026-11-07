<?php

namespace App\Models;

use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property string $id
 */
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    use HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'name',
    ];

    /**
     * @return HasMany<Spot, $this>
     */
    public function spots(): HasMany
    {
        return $this->hasMany(Spot::class);
    }

    /**
     * @return HasManyThrough<Item, Spot, $this>
     */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(Item::class, Spot::class);
    }
}
