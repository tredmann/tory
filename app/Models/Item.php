<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\AsVector;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Hidden(['embedding'])]
class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'storage_location_id',
        'title',
        'description',
        'photos',
    ];

    protected static function booted(): void
    {
        static::deleted(function (Item $item): void {
            Storage::disk('local')->delete($item->photos ?? []);
        });
    }

    /**
     * @return array{photos: 'array', embedding: 'Illuminate\\Database\\Eloquent\\Casts\\AsVector'}
     */
    protected function casts(): array
    {
        return [
            'photos' => 'array',
            'embedding' => AsVector::class,
        ];
    }

    /**
     * @return BelongsTo<StorageLocation, $this>
     */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }
}
