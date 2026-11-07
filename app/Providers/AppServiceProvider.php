<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureTrustedProxies();
    }

    /**
     * Behind a TLS-terminating proxy, URLs are only https if its headers are trusted.
     */
    protected function configureTrustedProxies(): void
    {
        $proxies = (string) config('app.trusted_proxies');

        if ($proxies !== '') {
            TrustProxies::at(array_map(trim(...), explode(',', $proxies)));
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        Table::configureUsing(fn (Table $table): Table => $table->defaultDateTimeDisplayFormat('d.m.Y H:i'));
        Schema::configureUsing(fn (Schema $schema): Schema => $schema->defaultDateTimeDisplayFormat('d.m.Y H:i'));

        DB::prohibitDestructiveCommands(
            $this->app->isProduction(),
        );

        Password::defaults(fn (): ?Password => $this->app->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
