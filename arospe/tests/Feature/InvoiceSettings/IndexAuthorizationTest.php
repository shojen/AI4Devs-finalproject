<?php

// Story 0103 -- authorization of the invoice settings screen: route and mount gates, read-only
// rendering, every write method refusing on its own, locked properties and the gallery event
// (T-15..T-20).

use App\Livewire\InvoiceSettings\Index;
use App\Models\InvoiceSetting;
use App\Models\Media;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Feature\InvoiceSettings\ScreenFixtures;
use Tests\Support\Invoices\Numbering;

beforeEach(function () {
    ScreenFixtures::boot();
});

/**
 * Assert exactly one "Privileged action refused" warning for the actor and ability.
 */
function assertOneRefusal(User $actor, string $ability): void
{
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && ($context['actor_id'] ?? null) === $actor->id
            && ($context['ability'] ?? null) === $ability)
        ->once();
}

// T-15
test('a view-only actor sees the stored values read-only: disabled fieldset, notice, no write controls', function () {
    InvoiceSetting::factory()->withIssuer()->create();
    ScreenFixtures::viewer();

    $component = Livewire::test(Index::class)
        ->assertSeeHtml('data-test="invoice-settings-readonly-notice"')
        ->assertSee('Arospe Handmade Sociedad Limitada')
        ->assertSeeHtml('data-test="next-number-standard"')
        ->assertSeeHtml('data-test="next-number-corrective"')
        ->assertDontSeeHtml('data-test="invoice-settings-save"')
        ->assertDontSeeHtml('data-test="choose-logo"')
        ->assertDontSeeHtml('data-test="remove-logo"')
        ->assertDontSeeHtml('data-test="set-start-standard"')
        ->assertDontSeeHtml('data-test="set-start-corrective"')
        ->assertDontSeeHtml('invoice-logo-gallery');

    expect(preg_match('/<fieldset[^>]*\sdisabled/', $component->html()))->toBe(1);
});

test('a manager sees the save button, the start controls and no read-only notice', function () {
    ScreenFixtures::manager();

    Livewire::test(Index::class)
        ->assertSeeHtml('data-test="invoice-settings-save"')
        ->assertSeeHtml('data-test="choose-logo"')
        ->assertSeeHtml('data-test="set-start-standard"')
        ->assertDontSeeHtml('data-test="invoice-settings-readonly-notice"');
});

test('a manager without media.view cannot choose a logo', function () {
    ScreenFixtures::actingWith('invoice-settings.view', 'invoice-settings.manage');

    Livewire::test(Index::class)
        ->assertSeeHtml('data-test="invoice-settings-save"')
        ->assertDontSeeHtml('data-test="choose-logo"')
        ->assertDontSeeHtml('invoice-logo-gallery');
});

// T-16
test('a view-only save is forbidden, logged once and leaves the row unchanged', function () {
    InvoiceSetting::factory()->withIssuer()->create();
    Log::spy();
    $viewer = ScreenFixtures::viewer();

    Livewire::test(Index::class)
        ->set('issuerLegalName', 'Renamed Ltd')
        ->call('save')
        ->assertForbidden();

    assertOneRefusal($viewer, 'update');
    expect(InvoiceSetting::current()->issuer_legal_name)->toBe('Arospe Handmade Sociedad Limitada');
});

// T-17
test('without invoice-settings.view the route is forbidden, mount is forbidden and the sidebar item is absent', function () {
    $actor = User::factory()->create();
    $this->actingAs($actor);

    $this->get(route('invoice-settings.index'))->assertForbidden();
    Livewire::test(Index::class)->assertForbidden();

    $dashboard = $this->get(route('dashboard'))->assertOk()->getContent();

    expect($dashboard)->not->toContain('sidebar-link-invoice_settings');
});

test('guests are redirected to the login page', function () {
    $this->get(route('invoice-settings.index'))->assertRedirect(route('login'));
});

test('a viewer reaches the screen and sees the sidebar item inside Store settings, labelled Invoice settings', function () {
    ScreenFixtures::viewer();

    $html = $this->get(route('invoice-settings.index'))->assertOk()->getContent();

    expect($html)->toContain('sidebar-link-invoice_settings')
        ->and($html)->toContain(__('invoice-settings.screen.title'));
});

test('the invoice_settings module item sits right after store_languages in the store_settings cluster, gated by the route ability', function () {
    $items = config('modules.items');
    $keys = array_keys($items);
    $item = $items['invoice_settings'] ?? [];

    expect($item['cluster'] ?? null)->toBe('store_settings')
        ->and($item['route'] ?? null)->toBe('invoice-settings.index')
        ->and($item['permissions'] ?? null)->toBe(['invoice-settings.view'])
        ->and($item['label'] ?? null)->toBe('navigation.items.invoice_settings')
        ->and($keys[array_search('store_languages', $keys, true) + 1] ?? null)->toBe('invoice_settings');

    $middleware = app('router')->getRoutes()->getByName('invoice-settings.index')->gatherMiddleware();

    expect($middleware)->toContain('can:invoice-settings.view');
});

// T-18
test('logoMediaId and startSeries are locked against client writes', function (string $property) {
    ScreenFixtures::manager();

    expect(fn () => Livewire::test(Index::class)->set($property, 'standard'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with(['logoMediaId', 'startSeries']);

// T-19
test('the logo event with an array id or an unknown id is a silent no-op: no error, no log, logo unchanged', function (mixed $id) {
    Log::spy();
    ScreenFixtures::manager();

    Livewire::test(Index::class)
        ->dispatch('invoice-logo-selected', media: [['id' => $id]])
        ->assertHasNoErrors()
        ->assertSet('logoMediaId', null);

    Log::shouldNotHaveReceived('warning');
})->with([
    'array id' => [['nested' => 'x']],
    'unknown id' => ['00000000-0000-7000-8000-000000000000'],
    'integer id' => [42],
]);

test('the logo event is forbidden and logged once for a view-only actor and for a manager without media.view', function (array $permissions, string $ability) {
    $media = Media::factory()->create();
    Log::spy();
    $actor = ScreenFixtures::actingWith(...$permissions);

    Livewire::test(Index::class)
        ->dispatch('invoice-logo-selected', media: [['id' => $media->id]])
        ->assertForbidden()
        ->assertSet('logoMediaId', null);

    assertOneRefusal($actor, $ability);
})->with([
    'view-only' => [['invoice-settings.view'], 'update'],
    'no media.view' => [['invoice-settings.view', 'invoice-settings.manage'], 'viewAny'],
]);

test('removeLogo is forbidden for a view-only actor', function () {
    ScreenFixtures::viewer();

    Livewire::test(Index::class)->call('removeLogo')->assertForbidden();
});

test('the colour blur handlers do not write anything and the numbering sequences stay untouched', function () {
    ScreenFixtures::manager();
    $before = Numbering::snapshot();

    Livewire::test(Index::class)->set('primaryColor', '#abcdef');

    expect(Numbering::snapshot())->toBe($before)
        ->and(InvoiceSetting::query()->count())->toBe(0);
});
