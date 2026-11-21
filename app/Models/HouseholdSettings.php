<?php

namespace App\Models;

use App\Enums\Language;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property Language $content_language
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class HouseholdSettings extends Model
{
    use HasUuids;

    protected $table = 'household_settings';

    protected function casts(): array
    {
        return [
            'content_language' => Language::class,
        ];
    }

    public static function current(): self
    {
        return self::query()->sole();
    }

    public function changeContentLanguage(Language $language): void
    {
        $this->forceFill(['content_language' => $language])->save();
    }
}
