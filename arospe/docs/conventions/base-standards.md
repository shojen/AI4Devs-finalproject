# Base Standards

Baseline stack versions and project-structure standards for this Laravel + Livewire application. This is the "what shape does new code take" reference; for line-level style (types, braces, PHPDoc) see [code-style.md](code-style.md), and for identifier naming see [naming.md](naming.md).

## Table of Contents

- [Stack versions](#stack-versions)
- [Directory structure](#directory-structure) — split out to [directory-structure.md](directory-structure.md)
- [Model conventions](#model-conventions)
  - [Deleting a user goes through the model, not the query builder](#deleting-a-user-goes-through-the-model-not-the-query-builder)
  - [UUID primary keys](#uuid-primary-keys)
- [Livewire component convention: class-based, not single-file](#livewire-component-convention-class-based-not-single-file)
- [A `wire:ignore`d client-owned region — the app's first instance](#a-wireignored-client-owned-region--the-apps-first-instance)
- [Flux Free's `ui-dropdown` requires a real `<button>` trigger descendant](#flux-frees-ui-dropdown-requires-a-real-button-trigger-descendant--confirmed-twice-not-a-one-off)
- [Artisan-first workflow](#artisan-first-workflow)
- [Quality gates](#quality-gates)
  - [Steps 1 and 2 are the *iteration* forms](#steps-1-and-2-are-the-iteration-forms-run-both-unscoped-before-declaring-the-work-done)

## Stack versions

From [`composer.json`](../../composer.json):

| Package | Constraint |
| --- | --- |
| `php` | `^8.3` |
| `laravel/framework` | `^13.17` |
| `laravel/fortify` | `^1.37.2` |
| `livewire/livewire` | `^4.1` |
| `livewire/flux` | `^2.13.1` |
| `spatie/laravel-permission` | `^8.3` |
| `pestphp/pest` (dev) | `^4.7` |
| `pestphp/pest-plugin-browser` (dev) | `^4.3` |
| `larastan/larastan` (dev) | `^3.9` |
| `laravel/pint` (dev) | `^1.27` |

Frontend: Tailwind CSS v4 + Vite (see [`vite.config.js`](../../vite.config.js), [`package.json`](../../package.json)). `pest-plugin-browser` drives real-browser tests through Playwright (`playwright` `^1.61.1` in `package.json` `devDependencies`); the wired-up `tests/Browser/` suite, its one-time browser-binary setup, and what CI does and does not cover live in [../testing/frontend/playwright-setup.md](../testing/frontend/playwright-setup.md). Story 0021 makes [`resources/js/app.js`](../../resources/js/app.js) the app's **first real JS module** — a hand-rolled `contenteditable`/`document.execCommand` Alpine component for the WYSIWYG editor, registered on `alpine:init` — with **no new npm dependency**: Alpine already ships bundled inside Livewire 4's own build (`window.Alpine`), and no rich-text library (TipTap/Quill/Trix) was added, a deliberate decision recorded in the story's own D1.

## Directory structure

Split out into its own file, per [contracts.md](../contracts.md#doc-growth-management-rule)'s doc growth management rule — this was the largest section in this file. See **[Directory structure](directory-structure.md)** for the real `app/`/`routes/`/`database/`/`resources/`/`tests/` layout, the `app/Actions/` per-concern grouping rule, the app-owned-config-file-as-registry convention (`config/modules.php`, `config/html-sanitizer.php`), the controllers-sit-in-front-of-actions convention, and the authorization-rule-belongs-to-the-action convention.

## Model conventions

This codebase uses PHP 8 attributes for mass-assignment and serialization instead of the classic `$fillable`/`$hidden` properties, and a `casts()` method instead of a `$casts` property — both are Laravel 13 idioms:

```php
// app/Models/User.php
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
```

✅ Good — new models follow the same attribute-based style (`#[Fillable]`, `#[Hidden]`, `casts()` method).
❌ Bad — mixing the old property-based style into a new model (adapted to illustrate; not present in the repo):
```php
// anti-pattern — do not introduce this alongside the attribute-based style
class Post extends Model
{
    protected $fillable = ['title', 'body'];
    protected $hidden = ['internal_notes'];
}
```
Mixing both styles in the same codebase makes it unclear which one governs a given model at a glance.

`casts()` also carries enum casts (`'status' => UserStatus::class`, `'deleted_at' => 'datetime'`), and the **omission** of a column from `#[Fillable]` *is* this codebase's mass-assignment guard: `users.status` and `users.pending_email` are deliberately absent from `User`'s `#[Fillable]` list, so the only way to write them is an explicit `forceFill()` **from one named place** — today the `app/Actions/Users/` actions that own the email-change flow, plus `User::delete()`'s obfuscation write (see below), each of which is the single writer of the columns it touches. When you add a column that no form may set, leave it out of `#[Fillable]` and write it that way — don't add it and then filter the input at each call site.

`SalesRegion` (task 0016) is the same convention at a larger scale and worth reading as the reference case: it declares `#[Fillable(['code', 'description', 'rate'])]` and leaves **eight** columns out, with `database/seeders/SalesRegionSeeder.php` as their single `forceFill()` writer. Two things generalise from it. First, the omission list is derived from *who may write the column*, not from how sensitive it looks: `slug` is omitted because a form that could change it would break the seeder's idempotency by duplicating the row, and `name` because a canonical name must stay refreshable on re-seed. Second, **columns coupled by an invariant are one mass-assignment decision, not two** — `is_active` is omitted *because* `is_default` is, since leaving one fillable invites exactly the split write the invariant forbids. See [security/seeder-safety.md](../security/seeder-safety.md#confirmed-safe-split-seeder-owned-from-administrator-configurable-columns-upsert-is-the-wrong-default).

`Media` (story 0019) is the same convention at its most lopsided, and the case to cite when someone argues an omission list is getting unwieldy: `#[Fillable(['title', 'description'])]` against **seven** omitted columns — `path`, `webp_path`, `avif_path`, `width`, `height`, `size_bytes` and `uploaded_by`. Every one is *server-derived*, which is the cleanest version of the test this convention actually applies: not "is this sensitive" but **"could a form legitimately supply this value at all"**. A width the client asserts is not a width; a path the client supplies is an arbitrary write into a web-served directory. The single writer is [`App\Actions\Media\StoreUploadedImage`](../../app/Actions/Media/StoreUploadedImage.php), which uses `Media::forceCreate([...])` with a literal key list — the same shape `App\Actions\Users\CreateUser` already uses, and the reason a plain `Media::create()` there would silently drop seven of nine columns rather than fail.

⚠️ **The omission is a mass-assignment guard, not an integrity guard, and this model is where the difference bites.** `save()` writes the whole dirty set rather than the `fill()` allow-list, so a caller who assigns `$media->path = …` directly still reaches the column — exactly the shape [security/model-instance-trust.md](../security/model-instance-trust.md) records for `SalesRegion`. **Story 0020 is where a `media` row first gets updated at all, and it is the case that shows the guard doing its job rather than the case that breaks it.** [`App\Actions\Media\UpdateMediaDetails`](../../app/Actions/Media/UpdateMediaDetails.php) writes through `$media->update(['title' => …, 'description' => …])` — the allow-listed path, with a literal two-key array rather than a `$request`-shaped payload — so the seven omitted columns are unreachable *and* the update is auditable by reading one line. The residual is unchanged and still worth knowing: nothing stops a *future* caller assigning a path column and calling `save()`, and no test would catch it. The convention's protection ends where `fill()` does.

Every property is documented with a `@property` PHPDoc block above the class, matching the actual database columns (see the block above `class User` in `app/Models/User.php`) — keep this block in sync with the migration whenever a column is added or removed (this is exactly the kind of drift the `docs-maintainer` skill and this file exist to catch).

### Deleting a user goes through the model, not the query builder

**Corrected 2026-09-10 (story 0042) — `App\Models\User` is no longer the one model using `Illuminate\Database\Eloquent\SoftDeletes`, and this sentence is quoted rather than silently rewritten, per this project's audit-authored-page convention.** It used to read: *"`App\Models\User` is the one model using `Illuminate\Database\Eloquent\SoftDeletes` today (task 0005), and it overrides `delete()` so that a delete also obfuscates the account's email, nulls `email_verified_at` / `pending_email`, and revokes the account's `password_reset_tokens` rows — all in one transaction."* `App\Models\Customer` (story 0042) is a second, deliberately unalike instance — see [database/schema-other.md](../database/schema-other.md#soft-deletes-and-why-the-users-reasoning-does-not-transfer) for why a customer's soft delete carries none of `User::delete()`'s obfuscation, and [security/soft-delete-patterns.md](../security/soft-delete-patterns.md) for which of that page's rules do and do not bind it. What follows is still about `users` specifically, and is unchanged: `App\Models\User` remains the **only** model with an override on `delete()` at all. What those semantics *are* belongs to [database/schema-users-auth.md](../database/schema-users-auth.md#soft-deletes); the convention here is narrower and easy to break by accident: **an override on `delete()` only runs for instance deletes**, so the query builder is not an equivalent shortcut.

✅ Good — delete a resolved instance, which is what every call site in the repo does:

```php
// app/Livewire/Users/Index.php — deleteUser()
$target->delete();
```

❌ Bad — a bulk delete through the builder (adapted to illustrate; not present in the repo):

```php
// anti-pattern — never do this against users
User::whereIn('id', $ids)->delete();
```

`Builder::delete()` never instantiates a model, so it silently skips the override entirely: the rows are stamped `deleted_at` while keeping their live email addresses and their still-valid password-reset tokens. Same trap for any future model that puts real behavior on `delete()` — put the behavior on the model, then keep every call site on instances.

### UUID primary keys

> **Eight live examples: `User` (Epic 1), `SalesRegion` (task 0016), `Media` (story 0019), `ProductCategory` (story 0023), `Product` (story 0024), `ProductAttributeType` + `ProductAttributeValue` (story 0028), and `ProductVariant` (story 0029).** All eight are real UUID (v7) PK models. `User` got there by conversion, per [ADR 0001 — UUID primary keys](../decisions/0001-uuid-primary-keys.md); `SalesRegion` was the first model in this repo created that way from day one, and remains the one to copy for a plain greenfield table. **`sales_regions` and `media` are not among the ADR's original seven entities** — both shipped under a confirmed project-wide policy (UUID v7 for every new Epic 2 business entity, with a high-volume geography lookup table excepted and left `bigint`) recorded in [ADR 0001's Amendment 1](../decisions/0001-uuid-primary-keys.md#amendment-1-2026-08-27--the-scope-is-the-policy-not-the-list-of-seven). **`product_categories`, `products`, `product_attribute_types`/`product_attribute_values` and `product_variants` are the opposite case**: all are literally among the ADR's original seven named entities, so none needed an amendment — see [ADR 0001's Amendment 2](../decisions/0001-uuid-primary-keys.md#amendment-2-2026-09-01--product-categories-lands-inside-the-original-seven), [Amendment 3](../decisions/0001-uuid-primary-keys.md#amendment-3-2026-09-01--products-is-the-second-of-the-original-seven-to-ship) and [Amendment 4](../decisions/0001-uuid-primary-keys.md#amendment-4-2026-09-04--product-variants-is-the-third-of-the-original-seven-to-ship) (`product_attribute_types`/`product_attribute_values` are the exception among these four — they fall under Amendment 1's general policy rather than being named in the ADR's own seven, per the task file's own D9). `product_media` (story 0024), `product_sales_region` (story 0026) and `product_variant_values` (story 0029) all have **no UUID PK of their own at all** — composite keys over already-UUID FKs, the same shape the vendored permission pivots use — so all three fall outside this convention entirely rather than counting as additional examples. Three of the ADR's originally-named six not-yet-implemented entities (blog categories, blog tags, blog posts) still do not exist in code. Read the ADR for rationale and [database/schema.md's Notes](../database/schema.md#notes) for what is actually keyed this way. This subsection is only the code-shape convention.

These models key on a UUID **version 7** generated by Laravel 13's native `HasUuids` trait (`Illuminate\Database\Eloquent\Concerns\HasUuids`), whose default `newUniqueId()` returns `Str::uuid7()` (time-ordered, not random UUIDv4). The convention:

- Add `use HasUuids;` to the model's trait list alongside whatever other traits it needs (e.g. `HasFactory`) — do not substitute a different UUID-generation trait or a custom `newUniqueId()` override (`HasUlids` was considered and rejected — see [ADR 0001](../decisions/0001-uuid-primary-keys.md)).
- Type the `@property` PHPDoc for `id` as `string`, not `int`.
- Do **not** declare `$keyType` or `$incrementing` as properties. The trait's `HasUniqueStringIds` concern already overrides `getKeyType()` / `getIncrementing()` as methods, so restating them as properties is redundant.
- Route-model binding needs no syntax change (`{model}` still binds on `id`). Note one behavioral change: `resolveRouteBindingQuery()` validates the parameter with `Str::isUuid()` first, so a malformed non-UUID route parameter throws `ModelNotFoundException` (a 404) immediately rather than running a doomed query.
- Factories need no change — the trait populates the key just before insert, exactly as today's auto-increment models never set `id` in their factory `definition()`.

✅ Good — the real, current shape, from `App\Models\User`: `HasUuids` sits in the trait list and `id` is `@property string`, with no `$keyType`/`$incrementing` properties:
```php
// app/Models/User.php
use Illuminate\Database\Eloquent\Concerns\HasUuids;

/**
 * @property string $id
 * @property string $name
 * // ...
 */
#[Fillable(['name', 'email', 'password'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;
}
```
❌ Bad — restating what the trait already provides (adapted to illustrate; not present in the repo):
```php
// anti-pattern — do not do this; HasUuids already overrides these as methods
class Product extends Model
{
    use HasUuids;

    protected $keyType = 'string';   // redundant
    public $incrementing = false;    // redundant
}
```

The migration side of this convention (`$table->uuid('id')->primary();`, `foreignUuid(...)`) is documented in [database/migrations.md](../database/migrations.md#uuid-primary-keys).

## Livewire component convention: class-based, not single-file

Livewire 4 supports single-file components (PHP + Blade in one `.blade.php`), but this project consistently uses the **class-based / multi-file** form: a `Livewire\Component` subclass in `app/Livewire/**` paired with a same-named kebab-case view in `resources/views/livewire/**` (see [naming.md](naming.md#livewire-components-and-views)):

```php
// app/Livewire/Settings/Appearance.php — minimal example of the pattern
namespace App\Livewire\Settings;

use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Appearance settings')]
class Appearance extends Component
{
    //
}
```

Every Livewire route in `routes/settings.php` is mounted with `Route::livewire('<uri>', <Component>::class)`, and every component declares its page `#[Title(...)]` attribute rather than setting the title from the Blade view. Follow this pattern for new settings/feature pages instead of introducing single-file components, to keep the codebase's one way of doing this.

## A `wire:ignore`d client-owned region — the app's first instance

Every Livewire component in this repo up to task 0021 lets Livewire own its whole rendered DOM: the server re-renders, Livewire morphs the difference into the page, and that is the entire lifecycle. [`App\Livewire\Components\WysiwygEditor`](../../app/Livewire/Components/WysiwygEditor.php) is the first to need a **carve-out** from that, and it establishes the shape a later component follows if it ever needs the same thing.

The problem a plain Livewire re-render cannot survive is a browser `Selection`/caret inside a `contenteditable` region a user is actively typing into: a server round trip that morphs that subtree — even one that produces byte-identical HTML — destroys the caret position and can discard in-flight input. The fix is `wire:ignore` on the editable `<div>` itself:

```blade
{{-- resources/views/livewire/components/wysiwyg-editor.blade.php --}}
<div
    x-ref="editor"
    wire:ignore
    contenteditable="{{ $disabled ? 'false' : 'true' }}"
    role="textbox"
    aria-multiline="true"
    data-test="wysiwyg-editor-region"
    x-on:input="onEditorInput()"
    x-on:blur="onEditorBlur()"
>{!! $value !!}</div>
```

Three rules come with a `wire:ignore`d region like this one, and they generalise past this one component:

- **The region is seeded from server state exactly once, in the initial server-rendered HTML, never re-injected by JS.** `{!! $value !!}` runs once, on first mount — because the div is `wire:ignore`d, Livewire never touches it again, so a *later* server-side write to the bound property (a host resetting its form, say) intentionally does **not** reach the DOM. This is a documented consequence for any consumer to know, not a bug: a `wire:ignore`d region has no built-in "refresh me" mechanism, and one must be added explicitly (an Alpine method the host can call) if a future consumer needs programmatic content replacement.
- **The region syncs back to the server at defined points only, never continuously.** `wire:model.live` on every keystroke was considered and rejected: it would round-trip the whole value on every character and make the caret's survival depend on Livewire's morph running successfully on every keystroke — the exact risk `wire:ignore` exists to remove — for no benefit, since the value is only needed when the host form saves. `WysiwygEditor` instead debounces `$wire.set('value', editorEl.innerHTML)` 400 ms after `input`, plus one explicit call after a discrete action that does not reliably fire a native `input` event (an image insertion via `execCommand('insertHTML', ...)`).
- **`{!! !!}` unescaped output is safe here only because of what the value already is, not because the region is client-owned.** The seeded value is untrusted HTML by construction (a `#[Modelable]` property a consumer's `wire:model` writes through), and this component performs **no** sanitization of its own — it is rendered back out exactly as received. That is a *load-bearing dependency on the consumer*, not a property of `wire:ignore`: whatever persisted column ends up bound here must be sanitized server-side on its own write path before this component ever renders it, or the `{!! !!}` becomes a stored-XSS sink. See [api/products.md](../api/products.md#applivewirecomponentswysiwygeditor--the-gallerys-first-real-consumer-and-the-second-routeless-gated-component) for which consumers already close this dependency and how.

**When to reach for this**: only for a region a browser API (here, `contenteditable`/`Selection`) actively owns and would fight a server re-render over. Do not reach for `wire:ignore` as a general performance shortcut — every other component in this app re-renders normally, and that remains the default.

## Flux Free's `ui-dropdown` requires a real `<button>` trigger descendant — confirmed twice, not a one-off

`flux:dropdown` (`ui-dropdown`) is this codebase's default open/close mechanism for a popover — it is what `resources/views/components/desktop-user-menu.blade.php` and `resources/views/layouts/app/sidebar.blade.php` already use, and both stories that needed a *new* popover assumed it would work the same way there. Neither could use it.

`ui-dropdown` resolves its trigger with a hard requirement, `this.querySelector("button")` (`vendor/livewire/flux/dist/flux.min.js`), and `ui-menu`'s own `boot()` unconditionally attaches a keydown listener to whatever that resolves to. A trigger element that renders no `<button>` descendant — `<flux:input>` renders only an `<input>` — makes `querySelector("button")` return `null`, and `w(null, "keydown", ...)` then throws `Cannot read properties of null (reading 'addEventListener')` on **every page load**, confirmed live via `assertNoJavaScriptErrors()` in both cases rather than assumed from reading the stub. `flux:dropdown` was never designed for "a text input triggers a live result list" — it is designed for "a button opens a static action menu" — and the trigger-resolution requirement is where that design assumption becomes a hard runtime failure rather than a styling mismatch.

The fallback is the same hand-assembled popover both stories independently reached for: an `x-data="{ open: false }"` wrapper, `x-show`/`x-cloak` on the popover body, `x-on:click.outside="open = false"`, and `x-on:keydown.escape.window` for dismissal — real Flux presentational subcomponents (`flux:menu.group`, `flux:menu.item`, and so on) kept for their styling, with only the outer `ui-dropdown`/`ui-menu` wrapper replaced. Story 0021's `wysiwyg-editor.blade.php` established this for its link-insertion popover; story 0022's `searchable-multi-select.blade.php` needed it again for its results dropdown, for the identical `querySelector("button")` reason, and its own file-banner comment documents the mechanism verified live rather than re-deriving it.

**The rule this confirms**: `flux:dropdown` is safe to reach for only when its trigger element genuinely renders a `<button>` — verify this by executing `assertNoJavaScriptErrors()` against the real page before trusting it, not by reading the Blade source, since the failure is entirely inside a vendored JS bundle no static read will show you. Any future popover trigger that is not itself a `<flux:button>`-rendering element (a `flux:input`, a custom trigger, a table cell) inherits the same constraint, and the manual `x-show`/`x-cloak`/`click.outside` shape above is the established fallback rather than something to reinvent per component.

## Artisan-first workflow

Per project `CLAUDE.md` (Laravel Boost guidelines): use `php artisan make:*` to scaffold new files (models, migrations, controllers, tests, etc.) instead of hand-writing boilerplate, and pass `--no-interaction` plus the correct options. Use `php artisan make:test --pest <Name>` for tests (see [pest-testing skill](../../.claude/skills/pest-testing/SKILL.md)).

## Quality gates

Every PHP change in this repo should pass, in this order, before being considered done:

1. `php artisan test --compact --filter=<Name>` — narrowest relevant test(s) first, matching [`tests/Feature/**`](../../tests) structure.
2. `vendor/bin/pint --dirty --format agent` — auto-fixes formatting against the `laravel` preset (`pint.json`).
3. Larastan level 7 (`phpstan.neon`) for static analysis on `app/`, `bootstrap/app.php`, `config/`, `database/`, `routes/`.

### Steps 1 and 2 are the *iteration* forms. Run both unscoped before declaring the work done

Both commands above take a scope argument, and **a narrowed gate reports "pass", not "not checked"** — nothing in either one's output distinguishes "I looked and found nothing" from "I looked at almost nothing". Task 0010 shipped past both of them at once (see [errors-log.md](../errors-log-archive.md#both-of-this-projects-per-change-quality-gates-are-scoped-by-default-and-both-silently-passed--2026-08-20)), so the completion form is now stated explicitly:

```bash
vendor/bin/pint --format agent   # NOT --dirty
php artisan test                 # NOT --filter
```

- **`--dirty` inspects only files with *uncommitted* changes**, so it becomes a complete no-op the moment the work is committed — it is inversely coupled to commit hygiene, failing hardest exactly when the workflow is being followed best. It is the right tool mid-edit and the wrong last check on a committed branch.
- **`--filter` cannot observe a change's effect on the rest of the suite.** This matters far more often than it looks: a story that registers a **model event, an observer, a global scope, or middleware** has a blast radius of the whole suite by construction, however narrowly its own feature is scoped. `App\Models\Role`'s holder-count `deleting` guard was specified as part of the roles-CRUD story and reads as scoped to it — it binds every role in every test in the repo.

Use the scoped forms freely while iterating; the unscoped runs are what counts as the record.

**`php artisan test --parallel` is an equally valid unscoped record, and the faster one** (measured on this repo's own 950-test suite: ~2.6x on this project's dev container — see [testing/ci/commands.md#run-in-parallel](../testing/ci/commands.md#run-in-parallel)). It runs every test in every suite exactly like the plain unscoped form; `--parallel` changes how the work is distributed across processes, not what gets checked. CI runs it this way since the test-performance review that measured it. The one thing `--parallel` needs that the sequential form doesn't: `storage/framework/views` must sit on a filesystem that tolerates concurrent writes — see the ⚠️ in the linked section if you rebuild the Sail image and hit `tempnam()` errors under load.

_Last updated: 2026-09-11 — Doc growth management pass (docs/contracts.md#doc-growth-management-rule), not a story. Condensed the Directory Structure section's accumulated "this codebase's Nth/third/fourth/fifth shipped instance of the same pattern" counting narrative for `Products/`'s `SyncProductGallery`/`SyncProductSalesRegions`/`SyncProductAttributeValues` paragraphs into tighter prose that keeps the actual rule (a collaborator invoked only by an already-authorized action needs no gate of its own), every class name and every reachability-test reference — no fact removed. Left every heading, every ✅/❌ example and every block explicitly marked "quoted rather than silently rewritten" (the story 0025/0042 corrections) untouched, since other docs and `ai-spec/tasks/` files link into this file's headings by anchor.

_Previously: 2026-09-10 — Story 0043 (Customers — "new customer" notification, backend). Extended `Actions/Customers/` with `NotifyCustomerCreated` and `Notifications/` with `CustomerCreated`. Story 0042 (same day) added `SoftDeletes` to `App\Models\Customer`, with no `delete()` override unlike `User`'s, and `CustomerPolicy::delete()` as a fourth flat ability. No convention rule changed by either story. This file's longer prior `_Previously:` chain is folded into this single line per [contracts.md](../contracts.md#doc-growth-management-rule) — no content changed or lost; see git history for the full prior chain if needed._
