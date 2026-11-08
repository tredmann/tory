<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Enums\UserStatus;
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
 * @property UserRole $role
 * @property Carbon|null $locked_at
 * @property Carbon|null $invite_expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'invite_token'])]
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => 'member',
    ];

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
            'role' => UserRole::class,
            'locked_at' => 'datetime',
            'invite_expires_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function status(): UserStatus
    {
        return match (true) {
            $this->isLocked() => UserStatus::Locked,
            $this->email_verified_at === null => UserStatus::Invited,
            default => UserStatus::Active,
        };
    }

    /**
     * Creates an unverified user whose password nobody knows; the invite link sets the real one.
     */
    public static function invite(string $name, string $email, UserRole $role): self
    {
        $user = new self(['name' => $name, 'email' => $email, 'password' => Str::random(64)]);
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function assignRole(UserRole $role): void
    {
        $this->forceFill(['role' => $role])->save();
    }

    /**
     * Only the SHA-256 of the token is stored, so a database leak yields no usable link.
     */
    public function issueInviteLink(): string
    {
        $token = Str::random(32);

        $this->forceFill([
            'invite_token' => hash('sha256', $token),
            'invite_expires_at' => now()->addHours(24),
        ])->save();

        return url('/invite/'.$token);
    }

    public static function findByInviteToken(string $token): ?self
    {
        return self::query()
            ->where('invite_token', hash('sha256', $token))
            ->where('invite_expires_at', '>=', now())
            ->whereNull('locked_at')
            ->first();
    }

    public function acceptInvite(string $password): void
    {
        $this->forceFill([
            'password' => $password,
            'email_verified_at' => $this->email_verified_at ?? now(),
            'invite_token' => null,
            'invite_expires_at' => null,
        ])->save();
    }

    public function lock(): void
    {
        $this->forceFill([
            'locked_at' => now(),
            'invite_token' => null,
            'invite_expires_at' => null,
        ])->save();
    }

    public function unlock(): void
    {
        $this->forceFill(['locked_at' => null])->save();
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
        return ! $this->isLocked();
    }
}
