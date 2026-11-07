<?php

namespace App\Providers\Filament;

use App\Http\Controllers\PhotoController;
use App\Http\Controllers\SaveThemeMode;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Filament\Auth\Pages\Register;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $selfService = (bool) config('auth.self_service');

        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->registration($selfService ? Register::class : null)
            ->passwordReset($selfService ? RequestPasswordReset::class : null, $selfService ? ResetPassword::class : null)
            ->emailVerification()
            ->profile()
            ->globalSearch(false)
            ->authenticatedRoutes(function (Panel $panel): void {
                Route::post('/theme', SaveThemeMode::class)
                    ->middleware($panel->getEmailVerifiedMiddleware())
                    ->name('theme');
                Route::get('/photos/thumb/{path}', [PhotoController::class, 'thumbnail'])
                    ->where('path', '.+')
                    ->middleware($panel->getEmailVerifiedMiddleware())
                    ->name('photos.thumbnail');
                Route::get('/photos/{path}', [PhotoController::class, 'original'])
                    ->where('path', '(?!thumb/).+')
                    ->middleware($panel->getEmailVerifiedMiddleware())
                    ->name('photos.original');
            })
            ->renderHook(
                PanelsRenderHook::HEAD_START,
                fn (): View => view('filament.theme-mode'),
            )
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): HtmlString => new HtmlString('<style>.tory-active-provider { outline: 2px solid var(--success-500); } .tory-visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }</style>'),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): HtmlString => new HtmlString(Blade::render("@vite(['resources/js/slideshow.js', 'resources/js/item-photos.js', 'resources/js/bulk-photos.js', 'resources/css/item-grid.css', 'resources/css/tory-setup.css'])")),
            )
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
