# Routes (no REST API yet)

## Table of Contents

- [Why this file exists](#why-this-file-exists)
- [App-owned routes](#app-owned-routes)
- [Per-area route documentation](#per-area-route-documentation)
- [`email-change.confirm` — the first app-owned route deliberately outside `auth`](#email-changeconfirm--the-first-app-owned-route-deliberately-outside-auth)
- [Fortify-owned auth routes](#fortify-owned-auth-routes)
- [Passkeys-owned routes](#passkeys-owned-routes)
- [Adding a real API](#adding-a-real-api)

## Why this file exists

This app has **no `routes/api.php`** and no `Illuminate\Http\Resources\Json\JsonResource` classes — there is nothing that fits `api/<resource>.md` yet. This file documents the real contract surface that exists today: server-rendered/Livewire routes, one domain controller, plus the auth routes registered by `laravel/fortify` and `laravel/passkeys`. When real API resource controllers are added, split this file into `api/<resource>.md` per resource and update this file to link to them, per the placement rule in the `docs-maintainer` skill.

Full current route list can always be regenerated with `php artisan route:list`.

**`routes/console.php` is not a contract surface.** Story 0064 created it (`bootstrap/app.php` had referenced it since the skeleton) to hold the `Schedule::` entries; it registers no HTTP route, so `php artisan route:list` showing nothing new for it is not a gap. Its one entry runs `blog:publish-scheduled-posts` every minute — see [architecture/overview.md](../architecture/overview.md#where-things-live).

## App-owned routes

Declared in [`routes/web.php`](../../routes/web.php) and the per-area files it requires, [`routes/settings.php`](../../routes/settings.php), [`routes/roles.php`](../../routes/roles.php), [`routes/users.php`](../../routes/users.php), [`routes/sales-regions.php`](../../routes/sales-regions.php), [`routes/product-categories.php`](../../routes/product-categories.php), [`routes/product-attribute-types.php`](../../routes/product-attribute-types.php), [`routes/products.php`](../../routes/products.php), [`routes/shipping.php`](../../routes/shipping.php), [`routes/payment-methods.php`](../../routes/payment-methods.php), [`routes/customers.php`](../../routes/customers.php) and [`routes/orders.php`](../../routes/orders.php) and [`routes/blog-tags.php`](../../routes/blog-tags.php), [`routes/blog-categories.php`](../../routes/blog-categories.php) and [`routes/blog-posts.php`](../../routes/blog-posts.php). One file per functional area is now the convention: a new module screen gets its own `routes/<area>.php` with its own `auth` + `verified` group, `require`d from `web.php`, rather than another entry in a growing `web.php`.

**Adding a gated module route for a later epic?** [Users & Roles routes](users-and-roles.md) and [Sales Regions routes](sales-regions.md) describe what `users.index`, `roles.index` and `sales-regions.index` *are*; the reusable shape they establish — one `can:<permission>` per route as the plain alias string, plus the three alternatives rejected (Spatie's `permission:`, a group-level gate, and Laravel's `->can()` route sugar) and the four vendor-verified properties that come with it — is owned by [architecture/authorization.md](../architecture/authorization/how-to-gate.md#the-copyable-module-gate-pattern-and-the-three-alternatives-rejected), not repeated here. Task 0012 pinned the first two routes' behaviour end to end in [`tests/Feature/Authorization/ModuleRouteAccessTest.php`](../../tests/Feature/Authorization/ModuleRouteAccessTest.php) — cross-gate independence in both directions, the Super Admin bypass on each, the two refusals asserted to name no permission, and a revoke/grant taking effect on the next HTTP request — without changing either route's URI, name or middleware. Task 0017 did **not** extend that file; `sales-regions.index`'s own four-case HTTP block (guest → login, no permission → 403, holder → 200, Super Admin holding zero permission rows → 200) lives in [`tests/Feature/SalesRegions/IndexTest.php`](../../tests/Feature/SalesRegions/IndexTest.php) instead, which is a gap worth knowing about rather than a decision: the cross-gate independence assertions that make `ModuleRouteAccessTest` valuable are still written against two routes, not three.

> ⚠️ **Since story 0019 this table no longer shows every permission-gated surface in the app, and that is by design rather than an omission to fix.** [`App\Livewire\Media\Gallery`](../../app/Livewire/Media/Gallery.php) — the Shared Media Gallery — has **no route, no `can:` middleware and no `config/modules.php` entry**, because PRD §2.3 makes it a **modal Products and Blog embed** rather than a page, and there is no standalone Media Library screen this phase. It is nonetheless a real, permission-gated surface, reached over Livewire's own `/livewire/update` endpoint. So the rule this file has repeated for four routes — *"the middleware column understates what protects it"* — arrives at its limit here: for this component there is no middleware column at all, and the in-component gates are the whole perimeter, not the second of two layers. Do not add a `GET /media` route or a sidebar entry to "fix" the asymmetry. **Story 0020 made this the component's shipped, consumable shape and it now has its own subsection in [Products & Media routes](products.md)** — [`App\Livewire\Media\Gallery`](products/routeless-components.md#applivewiremediagallery--a-gated-surface-with-no-route-and-how-a-consumer-embeds-it) — because a picker story binding to it needs the contract, not only the warning. **Story 0021 adds the same asymmetry a second time**, one layer further out — [`App\Livewire\Components\WysiwygEditor`](products/routeless-components.md#applivewirecomponentswysiwygeditor--the-gallerys-first-real-consumer-and-the-second-routeless-gated-component) is itself a `Gallery` consumer with no route of its own, the pattern's second real instance. **Story 0027 is what both of them were waiting for** rather than a third instance of the same asymmetry: `App\Livewire\Products\Editor` is a real, routed page (`products.create`/`products.edit`) that embeds both `Gallery` (twice) and `WysiwygEditor` (once), so neither routeless component needed a browser test to visit any more — the temporary `dev.media-gallery-harness` scaffolding that story 0020 registered for exactly that purpose (and story 0021 extended) is **retired**, per [the fallback documented in Products & Media routes](products/products-index-and-editor.md#productsindex-productscreate-and-productsedit--the-fifth-permission-gated-route-family).

**A gated module route is only half the job.** Since task 0013 the sidebar is driven by [`config/modules.php`](../../config/modules.php), so shipping a module screen means adding its route *and* one registry entry naming the same single ability — otherwise the screen exists but nothing links to it. That registry's shape and its six rules — the sixth, added 2026-09-07 ahead of [story 0080](../../ai-spec/tasks/done/0080-sidebar-navigation-grouping-and-nesting.md)'s own implementation and now describing the real, shipped `groups`/`clusters`/`items` schema that story built and closed — requires a new entry to be placed under its PRD-assigned top-level group and nested under its parent module's cluster when it is a sub-resource — are owned by [architecture/authorization.md](../architecture/authorization/how-to-gate.md#the-second-half-of-a-module-gate-the-sidebar-registry) too; `tests/Feature/Navigation/SidebarModuleGatingTest.php` asserts mechanically that every entry's `permissions` set-equals its route's real `can:` middleware, so the two halves cannot drift. **Since task 0018 every gated route has (mostly) had its registry entry on shipping** — `sales-regions.index` sat in the linkless half-state between tasks 0017 and 0018 (exactly as `roles.index` did between 0010 and 0013), and `product-categories.index` (story 0025) and `products.index` (story 0027) each shipped their entry in the same story as their screen. Nothing about `<x-sidebar-nav />` itself has ever needed touching for a fifth entry in a row — task 0013's component still reads `config/modules.php` unmodified, which is the registry's central claim ("a later epic appends data, never behavior") holding again. **Story 0028 reopened that half-state a third time, and story 0030 is what closes it** — the same split 0017/0018 and 0004/0006 already established. `config/modules.php` gained a `product_attribute_types` entry (`icon: swatch`, `route: product-attribute-types.index`, `current_when: product-attribute-types.*`, `permissions: ['products.view']`) with matching `lang/{en,es}/navigation.php` leaves, and — the registry's now-**sixth** confirmation in a row — `resources/views/components/sidebar-nav.blade.php` needed no edit at all. **It shipped as a flat item in the `platform` group; story 0080's registry restructuring retired that flat group entirely and nests it inside the `products` cluster instead** (within the new `store` group), alongside `products` and `product_categories` — see those two entries' own subsections in [Products & Media routes](products.md), and D-4 in [the story's own task file](../../ai-spec/tasks/done/0080-sidebar-navigation-grouping-and-nesting.md), for the full shape.

| Method | URI | Name | Middleware | Handler |
| --- | --- | --- | --- | --- |
| GET | `/` | `home` | — | `view('welcome')` |
| GET | `/dashboard` | `dashboard` | `auth`, `verified` | `view('dashboard')` |
| GET | `/users` | `users.index` | `auth`, `verified`, `can:users.view` | `App\Livewire\Users\Index` |
| GET | `/roles` | `roles.index` | `auth`, `verified`, `can:roles.manage` | `App\Livewire\Roles\Index` |
| GET | `/taxes/sales-regions` | `sales-regions.index` | `auth`, `verified`, `can:sales-regions.view` | `App\Livewire\SalesRegions\Index` |
| GET | `/product-categories` | `product-categories.index` | `auth`, `verified`, `can:products.view` | `App\Livewire\ProductCategories\Index` |
| GET | `/products` | `products.index` | `auth`, `verified`, `can:products.view` | `App\Livewire\Products\Index` |
| GET | `/products/create` | `products.create` | `auth`, `verified`, `can:products.view` | `App\Livewire\Products\Editor` |
| GET | `/products/{product}/edit` | `products.edit` | `auth`, `verified`, `can:products.view` | `App\Livewire\Products\Editor` |
| GET | `/products/attribute-types` | `product-attribute-types.index` | `auth`, `verified`, `can:products.view` | `App\Livewire\Products\AttributeTypes\Index` |
| GET | `/shipping/zones` | `shipping.zones.index` | `auth`, `verified`, `can:shipping.view` | `App\Livewire\Shipping\Zones` |
| GET | `/shipping` | `shipping.index` | `auth`, `verified`, `can:shipping.view` | `App\Livewire\Shipping\Index` |
| GET | `/payment-methods` | `payment-methods.index` | `auth`, `verified`, `can:payment-methods.view` | `App\Livewire\PaymentMethods\Index` |
| GET | `/customers` | `customers.index` | `auth`, `verified`, `can:customers.view` | `App\Livewire\Customers\Index` |
| GET | `/customers/{customer}` | `customers.show` | `auth`, `verified`, `can:customers.view` | `App\Livewire\Customers\Show` |
| GET | `/orders` | `orders.index` | `auth`, `verified`, `can:orders.view` | `App\Livewire\Orders\Index` |
| GET | `/orders/{order}` | `orders.show` | `auth`, `verified`, `can:orders.view` | `App\Livewire\Orders\Show` |
| GET | `/blog/tags` | `blog-tags.index` | `auth`, `verified`, `can:blog.view` | `App\Livewire\BlogTags\Index` |
| GET | `/blog/categories` | `blog-categories.index` | `auth`, `verified`, `can:blog.view` | `App\Livewire\BlogCategories\Index` |
| GET | `/blog/posts` | `blog-posts.index` | `auth`, `verified`, `can:blog.view` | `App\Livewire\BlogPosts\Index` |
| GET | `/blog/posts/create` | `blog-posts.create` | `auth`, `verified`, `can:blog.view` | `App\Livewire\BlogPosts\Editor` |
| GET | `/blog/posts/{blogPost}/edit` | `blog-posts.edit` | `auth`, `verified`, `can:blog.view` | `App\Livewire\BlogPosts\Editor` |
| ANY | `/settings` | — | `auth` | redirect → `settings/profile` |
| GET | `/settings/profile` | `profile.edit` | `auth` | `App\Livewire\Settings\Profile` |
| GET | `/settings/appearance` | `appearance.edit` | `auth`, `verified` | `App\Livewire\Settings\Appearance` |
| GET | `/settings/security` | `security.edit` | `auth`, `verified`, `password.confirm` | `App\Livewire\Settings\Security` |
| GET | `/settings/email/confirm/{user}/{hash}` | `email-change.confirm` | `signed`, `throttle:6,1` (**no `auth`**) | `App\Http\Controllers\ConfirmEmailChangeController` |
| GET | `/.well-known/passkey-endpoints` | `well-known.passkeys` | — | inline closure, returns JSON `{enroll, manage}` |

```php
// routes/settings.php
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/security', Security::class)
        ->middleware(['password.confirm'])
        ->name('security.edit');
});
```

`security.edit` is the only route with the extra `password.confirm` **middleware**, because it manages 2FA and passkeys (see [architecture/authentication.md](../architecture/authentication.md)). It is no longer the only place in the app that requires a recently confirmed password: since task 0015a five operations behind `users.index` require one too, enforced **in-method** rather than at the route (and therefore invisible in this table's middleware column) — see the `users.index` subsection in [Users & Roles routes](users-and-roles.md) and [architecture/authorization.md](../architecture/authorization/step-up-and-refusal-logging.md#step-up-authentication--the-third-layer).
## Per-area route documentation

Each permission-gated route family has its own file, split out of what used to be one long file per [contracts.md](../contracts/token-and-doc-rules.md#doc-growth-management-rule)'s doc growth management rule:

- **[Users & Roles routes](users-and-roles.md)** — `users.index` (the first permission-gated route) and `roles.index` (the second).
- **[Sales Regions routes](sales-regions.md)** — `sales-regions.index` (the third permission-gated route).
- **[Products & Media routes](products.md)** — `product-categories.index` (the fourth), the `products.index`/`.create`/`.edit` family (the fifth), `product-attribute-types.index` (also the fifth, a separate route), and the two routeless, gated Products/Media shared components (`App\Livewire\Media\Gallery`, `App\Livewire\Components\WysiwygEditor`).
- **[Shipping routes](shipping.md)** — `shipping.zones.index` (the sixth) and `shipping.index` (the seventh).
- **[Payment Methods routes](payment-methods.md)** — `payment-methods.index` (the eighth).
- **[Customers routes](customers.md)** — `customers.index` (the ninth) and `customers.show` (the tenth).
- **[Orders routes](orders.md)** — `orders.index` (the eleventh) and `orders.show` (the twelfth). For `orders.show` the middleware column above understates by two abilities: `orders.edit` (line items, status), `orders.edit` + `orders.refund` (cancel) and `orders.refund` (refund) are all enforced in-method, so none appears in this table.
- **[Blog routes](blog.md)** — `blog-tags.index` (the thirteenth), `blog-categories.index` (the fourteenth) and `blog-posts.index` / `.create` / `.edit` (the fifteenth to seventeenth). Tag delete is unconditional; category delete is hard-blocked with a count while any post uses it. For the three post routes the middleware column understates by one ability: `create` / `update` (editor) and `delete` / `restore` (list) are enforced in the components, so `blog.view` alone reaches the list but gets a 403 on create and edit.

### `email-change.confirm` — the first app-owned route deliberately outside `auth`

Every other `settings/*` route sits inside one of this file's two `auth` groups. This one is registered at the **file's top level**, next to `well-known.passkeys`, and its complete middleware list is `signed` + `throttle:6,1` — nothing else:

```php
// routes/settings.php — file top level, NOT inside either Route::middleware([...])->group(...)
Route::get('settings/email/confirm/{user}/{hash}', ConfirmEmailChangeController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('email-change.confirm');
```

The omission is the point, not an oversight: what the link proves is control of the **mailbox** (signed, address-bound, single-use, 60 minutes), not an authenticated session. Requiring `auth` would deadlock the case an administrator most needs it for — changing the address of an `Inactive` user, who cannot sign in and so could never reach the link that would activate them. `tests/Feature/Settings/EmailChangeTest.php` carries a dedicated "reachable while signed out" test precisely because every other test in that file runs `actingAs()` and would miss the regression.

Two more facts about this route:

- **It is also this repo's first signed route with a route-model-bound parameter**, which is why `bootstrap/app.php` now globally prepends `ValidateSignature` ahead of `SubstituteBindings` in the middleware priority list — otherwise a tampered `{user}` would 404 (binding failure) while any other tampering 403s, an oracle for "does this user id exist". The reasoning and the verified side effects across the whole `web` pipeline are in [security/signed-link-verification.md](../security/signed-link-verification.md#validatesignature-must-run-before-substitutebindings).
- **It responds in two shapes.** A tampered or expired link fails the signature check with a **403**; a still-validly-signed link whose address no longer matches (replay, supersede, cancel) is refused by the controller with a **302** to `profile.edit` carrying a `status` flash. Don't assert 403 for both.

The controller behind it is the repo's first domain controller — the convention it establishes (an HTTP boundary in front of an `app/Actions/` class) is documented in [conventions/base-standards.md](../conventions/directory-structure/controllers-and-authorization-rule.md#controllers-sit-in-front-of-actions-not-instead-of-them).

## Fortify-owned auth routes

Registered by `laravel/fortify` from `config/fortify.php`, not hand-written in this repo. Listed here because they are part of the real, callable contract surface (verified via `php artisan route:list`):

| Method | URI | Name |
| --- | --- | --- |
| GET/POST | `/register` | `register` / `register.store` |
| GET/POST | `/login` | `login` / `login.store` |
| POST | `/logout` | `logout` |
| GET/POST | `/forgot-password` | `password.request` / `password.email` |
| GET/POST | `/reset-password/{token}` | `password.reset` / `password.update` |
| GET/POST | `/email/verify`, `/email/verify/{id}/{hash}` | `verification.notice` / `verification.verify` |
| POST | `/email/verification-notification` | `verification.send` |
| GET/POST | `/two-factor-challenge` | `two-factor.login` / `two-factor.login.store` |
| GET/POST/DELETE | `/user/confirm-password`, `/user/confirmed-password-status` | `password.confirm*` |
| POST/DELETE | `/user/two-factor-authentication` | `two-factor.enable` / `two-factor.disable` |
| POST | `/user/confirmed-two-factor-authentication` | `two-factor.confirm` |
| GET | `/user/two-factor-qr-code`, `/user/two-factor-secret-key`, `/user/two-factor-recovery-codes` | `two-factor.qr-code` / `two-factor.secret-key` / `two-factor.recovery-codes` |
| POST | `/user/two-factor-recovery-codes` | `two-factor.regenerate-recovery-codes` |

Which of these are active depends on `config('fortify.features')` — see [architecture/authentication.md](../architecture/authentication.md) for what's actually enabled.

**One of these routes carries app-added middleware that `route:list` shows but `config/fortify.php` does not explain.** `POST /user/confirm-password` (`password.confirm.store`) runs `throttle:confirm-password` — 5/minute, keyed by user id when authenticated and by IP otherwise — appended by [`App\Providers\FortifyServiceProvider::configurePasswordConfirmationRateLimiting()`](../../app/Providers/FortifyServiceProvider.php) (task 0015a, decision D8). Unlike `login`, `two-factor` and `passkeys`, Fortify's own `routes.php` consults **no** `config('fortify.limiters.*')` key for this route, so there is no config hook to wire a limiter through the usual way; the app mutates the already-registered route object from an `$this->app->booted()` callback instead. Task 0015a is what made this endpoint load-bearing — it is now the sole barrier in front of the five step-up-gated Users operations — so a throttled 6th attempt is a **429** (Laravel's generic error page, not a worded inline message like Fortify's `login` limiter produces). `tests/Feature/Auth/PasswordConfirmationTest.php` asserts both the behaviour and the middleware's attachment, the latter because a change to the `booted()` mechanism could otherwise unthrottle the route silently.

## Passkeys-owned routes

Registered by `laravel/passkeys`:

| Method | URI | Name |
| --- | --- | --- |
| GET/POST | `/passkeys/login`, `/passkeys/login/options` | `passkey.login` / `passkey.login-options` |
| GET/POST | `/passkeys/confirm`, `/passkeys/confirm/options` | `passkey.confirm` / `passkey.confirm-options` |
| GET/POST | `/user/passkeys`, `/user/passkeys/options` | `passkey.store` / `passkey.registration-options` |
| DELETE | `/user/passkeys/{passkey}` | `passkey.destroy` |

Consumed from `App\Livewire\Settings\Security` (list/add/delete UI) — see [architecture/authentication.md](../architecture/authentication.md).

Not listed: asset/dev-tool routes with no domain meaning (`flux/*`, `livewire-*/js|css/*`, `storage/{path}`, `up`, Boost's `_boost/browser-logs`).

## Layout-mounted Livewire components (no route)

Not a route, so it has no row in the table above — recorded here because a route-shaped reader would otherwise conclude the app has no such thing.

| Component | Mounted from | Route | Gate |
| --- | --- | --- | --- |
| `App\Livewire\Notifications\Bell` (story 0057, relocated by 0057a) | `resources/views/layouts/app/sidebar.blade.php`, **once**, by name (`<livewire:notifications.bell />`), inside the persistent topbar (`data-test="topbar"`), at its right-hand end | none | `auth` only, inherited from the layout. No `can:` middleware, no `config/modules.php` entry, no policy — every query is scoped to `Auth::user()`, and no id from the client reaches a query |

Story 0057 first mounted it twice (desktop sidebar + mobile header) because no desktop topbar existed; story 0057a replaced that with one sticky topbar at every breakpoint, so its `data-test` hooks are unique per document again. The view branches on `notifications.type` for presentation only, with a permanent generic fallback for any unrecognized type.

**The topbar's title and subtitle** are declared per screen as Livewire named slots (`<x-slot:heading>` / `<x-slot:subheading>`, forwarded by `layouts/app.blade.php` to `layouts/app/sidebar.blade.php`); `#[Title]` still feeds `<title>` only. The topbar title is the page's only `<h1>`; a screen without a `subheading` renders no `topbar-subtitle` element at all (the customer detail screen, whose title is the customer's name). Copy lives in `lang/{en,es}/topbar.php` unless a screen already owned a title key. `resources/views/layouts/app/header.blade.php` (a horizontal-nav layout) is referenced by nothing and was left untouched.

## Adding a real API

When `routes/api.php` and API resource controllers appear, replace this file's structure with one `api/<resource>.md` per resource, each documenting real request/response JSON pulled from the controller/resource classes — do not add one preemptively.

_Last updated: 2026-09-26 — Story 0063 (blog posts list + editor). Added `blog-posts.index`, `.create` and `.edit` to the route table, the `routes/` file list and the [Blog routes](blog.md) entry. Also: Story 0064 (scheduled post auto-publish). Added the note that `routes/console.php` exists and is not a contract surface; no HTTP route changed. Earlier changes: 0062 added `blog-categories.index` (the fourteenth permission-gated route) to the route table, the `routes/` file list and the [Blog routes](blog.md) entry, 0060 had added `blog-tags.index`; the rest are folded per [contracts.md](../contracts/token-and-doc-rules.md#doc-growth-management-rule): 0055 added the Orders rows, 0057a moved the bell into a persistent topbar, 0057 added the layout-mounted section, 0047/0044 added the Customers rows, and the per-area split created the linked files._
