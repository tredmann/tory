<?php

namespace App\Providers\Filament;

use App\Filament\Auth\AcceptInvite;
use App\Http\Controllers\PhotoController;
use App\Http\Controllers\SaveThemeMode;
use App\Http\Middleware\LogOutLockedUser;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
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
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->emailVerification()
            ->profile()
            ->globalSearch(false)
            ->userMenuItems([
                Action::make('version')
                    ->label(fn (): string => 'Version '.config('app.version'))
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->disabled(),
            ])
            ->routes(fn () => Route::get('/invite/{token}', AcceptInvite::class)->middleware('throttle:10,1')->name('invite'))
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
                fn (): HtmlString => new HtmlString(Blade::render("@vite(['resources/js/slideshow.js', 'resources/js/item-photos.js', 'resources/js/bulk-photos.js', 'resources/js/item-gallery.js', 'resources/css/item-grid.css', 'resources/css/tory-setup.css', 'resources/css/ai-providers.css'])")),
            )
            ->colors([
                'primary' => array_replace(Color::hex('#C42A18'), [
                    500 => Color::hex('#C42A18')[600],
                    600 => '#C42A18',
                    700 => '#A82213',
                    800 => '#8F1C0F',
                ]),
                'gray' => Color::Stone,
            ])
            ->font('DM Sans')
            ->sidebarWidth('14rem')
            ->brandLogo(fn (): View => view('filament.brand-logo', ['mark' => asset('images/tory-mark.svg')]))
            ->darkModeBrandLogo(fn (): View => view('filament.brand-logo', ['mark' => asset('images/tory-mark-white.svg')]))
            ->brandLogoHeight('2rem')
            ->favicon(fn (): string => asset('images/tory-mark.svg'))
            ->navigationGroups([
                NavigationGroup::make('Inventar')->collapsible(false),
                NavigationGroup::make('Einstellungen')->collapsible(false),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                LogOutLockedUser::class,
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
