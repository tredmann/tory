<?php

namespace App\Policies;

use App\Models\User;

/**
 * No delete abilities, so Filament offers no deleting. An administrator can
 * neither demote, re-invite nor lock themselves, which keeps one administrator.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $target): bool
    {
        return $user->isAdmin();
    }

    public function changeRole(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target);
    }

    public function issueInvite(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target) && ! $target->isLocked();
    }

    public function lock(User $user, User $target): bool
    {
        return $user->isAdmin() && ! $user->is($target) && ! $target->isLocked();
    }

    public function unlock(User $user, User $target): bool
    {
        return $user->isAdmin() && $target->isLocked();
    }
}
