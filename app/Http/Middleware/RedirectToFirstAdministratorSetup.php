<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends every panel request of an installation without any user to the first administrator setup.
 */
class RedirectToFirstAdministratorSetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs('filament.admin.setup') && ! User::query()->exists()) {
            return redirect()->route('filament.admin.setup');
        }

        return $next($request);
    }
}
