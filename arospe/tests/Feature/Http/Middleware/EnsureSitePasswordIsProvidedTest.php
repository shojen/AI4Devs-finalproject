<?php

/**
 * app('config')->set(...) rather than a real .env value, since this middleware's
 * whole point is to be off by default (SITE_PASSWORD_PROTECTED=false in both
 * .env.example and phpunit.xml's implicit default) and every other test in the
 * suite relies on the "web" group never challenging it.
 */
function enableSitePasswordProtection(string $username, string $password): void
{
    config([
        'app.site_password_protection' => [
            'enabled' => true,
            'username' => $username,
            'password' => $password,
        ],
    ]);
}

test('the site is reachable unauthenticated when the gate is disabled', function () {
    $this->get('/')->assertOk();
});

test('an unauthenticated request is refused with a 401 and a WWW-Authenticate header when the gate is enabled', function () {
    enableSitePasswordProtection('demo', 'secret');

    $this->get('/login')
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});

test('the wrong credentials are refused', function () {
    enableSitePasswordProtection('demo', 'secret');

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('demo:wrong-password'),
    ])->get('/login')->assertUnauthorized();
});

test('the correct credentials are admitted', function () {
    enableSitePasswordProtection('demo', 'secret');

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode('demo:secret'),
    ])->get('/login')->assertOk();
});

test('enabling the gate with no username or password configured fails closed rather than admitting blank credentials', function () {
    config([
        'app.site_password_protection' => [
            'enabled' => true,
            'username' => null,
            'password' => null,
        ],
    ]);

    $this->withHeaders([
        'Authorization' => 'Basic '.base64_encode(':'),
    ])->get('/login')->assertUnauthorized();
});

test('the landing page stays public when the gate is enabled', function () {
    enableSitePasswordProtection('demo', 'secret');

    $this->get('/')->assertOk();
});

test('only the exact root path is exempt from the gate', function () {
    enableSitePasswordProtection('demo', 'secret');

    $this->get('/dashboard')->assertUnauthorized();
});
