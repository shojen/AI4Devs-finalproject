<?php

namespace App\Http\Middleware;

use App\Enums\UiLocale;
use App\Models\LocaleSetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetUiLocale
{
    /**
     * Resolve the application locale for the current request from the
     * authenticated user's stored admin UI locale preference, falling back
     * to the administrator-configured default (story 0068's
     * LocaleSetting::defaultUiLocale()) — never config('app.locale')
     * directly, which is a third tier reached only inside that accessor
     * (story 0066, D-6).
     *
     * Runs unconditionally on every request, authenticated or not: the `??`
     * fallback is what stops a previous request's locale leaking forward
     * into the next one in the same process (D-9). tryFrom(), never
     * from(), so a stale stored value degrades to the configured default
     * instead of throwing (D-5).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $stored = $request->user()?->ui_locale;

        App::setLocale(
            UiLocale::tryFrom((string) $stored)->value
                ?? LocaleSetting::defaultUiLocale()->value,
        );

        return $next($request);
    }
}
