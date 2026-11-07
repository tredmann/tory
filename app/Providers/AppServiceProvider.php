<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

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
        $this->configureApiDocs();
        $this->configurePassport();
    }

    /**
     * Connected Claude access lasts until the user disconnects it; consent is asked on a German page.
     */
    protected function configurePassport(): void
    {
        Passport::authorizationView('mcp.authorize');
        Passport::tokensExpireIn(CarbonInterval::years(100));
        Passport::refreshTokensExpireIn(CarbonInterval::years(100));
    }

    /**
     * The API docs are for verified panel users only; requests carry bearer tokens.
     */
    protected function configureApiDocs(): void
    {
        Gate::define('viewApiDocs', fn (User $user): bool => $user->hasVerifiedEmail());

        Scramble::configure()->withDocumentTransformers(
            fn (OpenApi $openApi) => $openApi->secure(SecurityScheme::http('bearer')),
        );
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
