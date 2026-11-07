<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts 403 with a German message for a token holder whose email is unverified.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasVerifiedEmail(), 403, 'Bitte bestätige zuerst deine E-Mail-Adresse.');

        return $next($request);
    }
}
