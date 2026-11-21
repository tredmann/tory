<?php

use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\LogOutLockedUser;
use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Laravel sorts authentication middleware ahead of everything else; the lock check
        // must run before it, or Filament answers 403 instead of sending the user to the login.
        $middleware->web(append: [LogOutLockedUser::class, SetLocale::class]);
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: LogOutLockedUser::class);
        $middleware->api(prepend: [ForceJsonResponse::class], append: [SetLocale::class]);

        // Guests on the OAuth authorization page log in through the panel; an MCP client
        // instead needs the 401 with its WWW-Authenticate header, whatever it accepts.
        $middleware->redirectGuestsTo(
            fn (Request $request): ?string => $request->is('mcp') ? null : route('filament.admin.auth.login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
