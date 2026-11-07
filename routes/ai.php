<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Mcp\Servers\ToryServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::oauthRoutes();

Mcp::web('/mcp', ToryServer::class)->middleware(['auth:api', EnsureEmailIsVerified::class]);
