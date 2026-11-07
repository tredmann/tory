<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Enums\ThemeMode;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property int|null $last_spot_id
 * @property ThemeMode|null $theme_mode
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'theme_mode' => ThemeMode::class,
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }

    /**
     * @return BelongsTo<Spot, $this>
     */
    public function lastSpot(): BelongsTo
    {
        return $this->belongsTo(Spot::class, 'last_spot_id');
    }

    /**
     * Passport's trait cannot be combined with Sanctum's (conflicting `$accessToken`
     * and `tokens()`), so the two relations the OAuth flow needs live here.
     *
     * @return MorphMany<Client, $this>
     */
    public function oauthApps(): MorphMany
    {
        return $this->morphMany(Passport::clientModel(), 'owner');
    }

    /**
     * @return HasMany<Token, $this>
     */
    public function oauthTokens(): HasMany
    {
        return $this->hasMany(Passport::tokenModel(), 'user_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }
}
