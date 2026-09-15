<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSitePasswordIsProvided
{
    /**
     * Gate the whole site behind a single shared HTTP Basic Auth credential,
     * read from config('app.site_password_protection'). A no-op unless
     * `enabled` is true, so it stays off unless SITE_PASSWORD_PROTECTED=true
     * is set for the environment that needs it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.site_password_protection.enabled')) {
            return $next($request);
        }

        $username = (string) config('app.site_password_protection.username');
        $password = (string) config('app.site_password_protection.password');

        // Fail closed: SITE_PASSWORD_PROTECTED=true with no credentials
        // configured must never fall back to comparing two empty strings
        // and letting an unauthenticated request through unchallenged.
        if ($username === '' || $password === '') {
            return response('Unauthorized.', 401, [
                'WWW-Authenticate' => 'Basic realm="'.config('app.name').'"',
            ]);
        }

        $providedUsername = (string) $request->getUser();
        $providedPassword = (string) $request->getPassword();

        if (
            hash_equals($username, $providedUsername)
            && hash_equals($password, $providedPassword)
        ) {
            return $next($request);
        }

        return response('Unauthorized.', 401, [
            'WWW-Authenticate' => 'Basic realm="'.config('app.name').'"',
        ]);
    }
}
