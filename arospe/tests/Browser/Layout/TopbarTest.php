<?php

use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerCreated;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\PermissionRegistrar;

/*
 * Story 0057a -- the persistent topbar: bell top right, page title + subtitle
 * on the left, on every authenticated screen. Selection by data-test hook,
 * never by translated copy; every case ends with assertNoJavaScriptErrors().
 * Structure is asserted by DOM containment (primary) with a loose bounding-box
 * check (secondary) -- pixel-exact placement is deliberately not pinned.
 */

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

function topbarBrowserAdministrator(): User
{
    $administrator = User::factory()->create();
    $administrator->assignRole('Administrator');

    return $administrator;
}

function topbarCountJs(string $selector): string
{
    return "document.querySelectorAll('{$selector}').length";
}

// Scenario Outline: The bell sits at the top right of the topbar (wide)
test('on a wide screen the bell is inside the right-hand side of the topbar and not in the sidebar', function () {
    $this->actingAs(topbarBrowserAdministrator());

    visit('/dashboard')
        ->assertScript(topbarCountJs('[data-test="topbar"] [data-test="notification-bell"]'), 1)
        // Document-wide too: the old double mount must not come back.
        ->assertScript(topbarCountJs('[data-test="notification-bell"]'), 1)
        ->assertScript(topbarCountJs('[data-test="sidebar"] [data-test="notification-bell"]'), 0)
        ->assertScript(
            "(() => { const r = document.querySelector('[data-test=\"notification-bell\"]').getBoundingClientRect(); return r.width > 0 && r.right > window.innerWidth - 120 && r.top < 80; })()",
            true,
        )
        ->assertNoJavaScriptErrors();
});

// ...and phone-sized
test('on a phone-sized screen the bell is still in the topbar beside the menu toggle and profile menu', function () {
    $this->actingAs(topbarBrowserAdministrator());

    visit('/dashboard')
        ->resize(390, 800)
        ->assertScript(topbarCountJs('[data-test="topbar"] [data-test="notification-bell"]'), 1)
        ->assertScript(
            "(() => { const r = document.querySelector('[data-test=\"notification-bell\"]').getBoundingClientRect(); return r.width > 0 && r.right <= window.innerWidth && r.top < 80; })()",
            true,
        )
        // The hamburger and the mobile profile dropdown must not be lost when the mobile-only
        // header is replaced by one topbar for every breakpoint (0057a R-4).
        ->assertScript(topbarCountJs('[data-test="sidebar-toggle"]'), 1)
        ->assertScript(topbarCountJs('[data-test="topbar-mobile-profile"]'), 1)
        ->assertNoJavaScriptErrors();
});

// A long title must not push the bell (or the hamburger/profile menu) off screen at 390px.
test('a long title truncates on a phone-sized screen without pushing the bell out', function () {
    $this->actingAs(topbarBrowserAdministrator());
    $customer = Customer::factory()->create([
        'name' => 'Maria del Carmen de la Concepción Fernández-Guadalupe y Montenegro',
    ]);

    visit('/customers/'.$customer->id)
        ->resize(390, 800)
        ->assertScript(
            "(() => { const r = document.querySelector('[data-test=\"notification-bell\"]').getBoundingClientRect(); return r.width > 0 && r.right <= window.innerWidth; })()",
            true,
        )
        ->assertScript(
            "(() => { const t = document.querySelector('[data-test=\"topbar-title\"]'); return t.scrollWidth >= t.clientWidth; })()",
            true,
        )
        ->assertNoJavaScriptErrors();
});

// Scenario Outline: The topbar shows each screen's title and subtitle
test('the topbar carries a title and a subtitle on representative screens', function (string $url) {
    $this->actingAs(topbarBrowserAdministrator());

    visit($url)
        ->assertScript(topbarCountJs('[data-test="topbar-title"]'), 1)
        ->assertScript("document.querySelector('[data-test=\"topbar-title\"]').textContent.trim().length > 0", true)
        ->assertScript("document.querySelector('[data-test=\"topbar-subtitle\"]').textContent.trim().length > 0", true)
        ->assertNoJavaScriptErrors();
})->with(['/dashboard', '/users', '/settings/profile', '/products/create']);

// Scenario: A customer's detail screen titles the topbar with the customer's name
test('the customer detail topbar shows the customer name and no subtitle', function () {
    $this->actingAs(topbarBrowserAdministrator());
    $customer = Customer::factory()->create(['name' => 'Marta Ejemplo']);

    visit('/customers/'.$customer->id)
        ->assertScript("document.querySelector('[data-test=\"topbar-title\"]').textContent.trim()", 'Marta Ejemplo')
        ->assertScript(topbarCountJs('[data-test="topbar-subtitle"]'), 0)
        ->assertNoJavaScriptErrors();
});

// Scenario: The topbar title follows the administrator to another screen (wire:navigate tripwire)
test('navigating from the dashboard to users updates the topbar title and subtitle', function () {
    $this->actingAs(topbarBrowserAdministrator());

    $page = visit('/dashboard');
    $dashboardTitle = $page->script("document.querySelector('[data-test=\"topbar-title\"]').textContent.trim()");

    $page->click('@sidebar-link-users')->wait(1)
        ->assertScript("document.querySelector('[data-test=\"topbar-title\"]').textContent.trim() !== ".json_encode($dashboardTitle), true)
        ->assertScript("document.querySelector('[data-test=\"topbar-title\"]').textContent.trim()", __('topbar.users.title'))
        ->assertScript("document.querySelector('[data-test=\"topbar-subtitle\"]').textContent.trim()", __('topbar.users.subtitle'))
        ->assertNoJavaScriptErrors();
});

// Scenario: The unread indicator shows from the topbar / the list is not obscured
test('the bell dropdown opened from the topbar is not obscured and a row can be clicked', function (int $width) {
    $administrator = topbarBrowserAdministrator();
    $customer = Customer::factory()->create();
    foreach (range(1, 3) as $ignored) {
        $administrator->notify(new CustomerCreated($customer));
    }
    $this->actingAs($administrator);

    $page = visit('/dashboard')->resize($width, 800);
    $page->assertScript(topbarCountJs('[data-test="topbar"] [data-test="notification-bell-unread-indicator"]'), 1);
    $page->script("document.querySelector('[data-test=\"notification-bell\"]').click()");

    $page->wait(1)
        ->assertScript(topbarCountJs('[data-test^="notification-item-"]'), 3)
        ->assertScript(
            "(() => { const row = document.querySelector('[data-test^=\"notification-item-\"]'); const r = row.getBoundingClientRect(); const top = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2); return row.contains(top); })()",
            true,
        )
        ->assertScript(
            "(() => { const rows = document.querySelectorAll('[data-test^=\"notification-item-\"]'); return rows[rows.length - 1].getBoundingClientRect().bottom <= window.innerHeight; })()",
            true,
        )
        ->assertNoJavaScriptErrors();

    // "A row can be clicked" is asserted by actually clicking it and following the navigation,
    // not only by the occlusion diagnostic above.
    $page->click('@notification-item-'.$administrator->notifications()->latest()->first()->id)
        ->wait(1)
        ->assertUrlIs(route('customers.show', $customer))
        ->assertNoJavaScriptErrors();
})->with([1280, 390]);
