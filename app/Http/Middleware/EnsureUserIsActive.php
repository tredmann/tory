<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts 403 with a German message for a token holder who is locked or whose email is unverified.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user instanceof User && $user->isLocked(), 403, __('This account is locked.'));
        abort_unless($user instanceof User && $user->hasVerifiedEmail(), 403, __('Please confirm your email address first.'));

        return $next($request);
    }
}
