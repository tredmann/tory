<?php

namespace App\Http\Middleware;

use App\Enums\Language;
use App\Models\HouseholdSettings;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the interface language of the request: the signed-in user's choice, else the browser's
 * language; guests get the household's language, except on a fresh install without any user.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request)->value);

        return $next($request);
    }

    private function resolve(Request $request): Language
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user->locale ?? $this->browserLanguage($request);
        }

        if (User::query()->exists()) {
            return HouseholdSettings::current()->content_language;
        }

        return $this->browserLanguage($request);
    }

    private function browserLanguage(Request $request): Language
    {
        return Language::from($request->getPreferredLanguage(['en', 'de']));
    }
}
