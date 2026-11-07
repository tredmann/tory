<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Mcp\Servers\ToryServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

Mcp::oauthRoutes(registration: false);

// Registration is open to anyone, so it is throttled; the discovery routes are not.
Route::post('oauth/register', OAuthRegisterController::class)
    ->middleware('throttle:10,1')
    ->name('mcp.oauth.register');

Mcp::web('/mcp', ToryServer::class)->middleware(['auth:api', EnsureEmailIsVerified::class]);
