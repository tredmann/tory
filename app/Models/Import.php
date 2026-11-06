<?php

namespace App\Models;

use App\Enums\ImportStatus;
use Database\Factories\ImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Import extends Model
{
    /** @use HasFactory<ImportFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'storage_location_id',
        'status',
        'photos',
        'title',
        'description',
        'error',
    ];

    protected static function booted(): void
    {
        static::deleted(function (Import $import): void {
            Storage::disk('local')->delete($import->photos ?? []);
        });
    }

    /**
     * @return array{status: 'App\\Enums\\ImportStatus', photos: 'array'}
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'photos' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<StorageLocation, $this>
     */
    public function storageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class);
    }
}
