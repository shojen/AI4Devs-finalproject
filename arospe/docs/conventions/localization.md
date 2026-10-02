# Localization Conventions

Forward-looking rules for any future code that needs to account for locale in this app — not a
changelog of story 0066/0068. This is the "what shape does new code take" reference for i18n-adjacent
work; for the naming of lang keys and boolean properties themselves, see
[naming/translation-keys-and-booleans.md](naming/translation-keys-and-booleans.md) (cross-referenced
below, not duplicated here). For how notification-locale resolution and the admin locale middleware
already work today, see
[architecture/authentication/features-registration-and-status.md#notification-locale-story-0066](../architecture/authentication/features-registration-and-status.md#notification-locale-story-0066)
and [architecture/overview.md](../architecture/overview.md); for the product-level split this doc
follows, see [PRD Epic 5 — Internationalization](../PRD/sections/epic-5-internationalization.md).

## The two layers, and why a change must never cross between them

This app has **two independent i18n layers**, deliberately not merged, with different value
domains, different scope, and different defaults:

- **Layer 1 — Admin UI language.** `users.ui_locale`, `App\Enums\UiLocale`, `App\Models\LocaleSetting`
  and `App\Http\Middleware\SetUiLocale`. Governs `App::getLocale()` for the **backoffice dashboard
  only** — staff-facing chrome, labels and notification emails. A closed, two-case set: `en`/`es`.
  See [database/schema-localization.md#locale_settings](../database/schema-localization.md#locale_settings).
- **Layer 2 — Store Languages.** `App\Models\StoreLanguage` (story 0068's catalog) and the
  translatable-content mechanism the backlog's `ai-spec/tasks/0070-*`–`0079-*` stories build on top
  of it. Governs what language **storefront-facing content** (product/category/blog title,
  description, body, slugs, SEO fields) is authored and shown in — an **open** set (any active
  catalog language an admin has added), with its own store-default independent of Layer 1's.
  See [database/schema-localization.md#store_languages](../database/schema-localization.md#store_languages).

**Rule: a future story that adds storefront/public-facing translation builds on the Layer 2
mechanism and never reads `users.ui_locale`, calls into `App\Enums\UiLocale`, or consults
`App\Models\LocaleSetting` to decide what language to show a shopper.** The two layers share no
default (`LocaleSetting::defaultUiLocale()`/`defaultNotificationLocale()` vs. `StoreLanguage`'s own
`is_default` row) and no scope (signed-in dashboard staff vs. anonymous storefront visitors) — do
not assume "the app's locale" is a single value with one source of truth just because both layers
happen to resolve through `App::setLocale()`/`App::getLocale()` today.

## Rule 1 — A notification off the `$user->notify()` path needs an explicit `->locale(...)`

`App\Models\User` implements `Illuminate\Contracts\Translation\HasLocalePreference` via
`preferredLocale()`, so **any notification sent with `$user->notify(new SomeNotification(...))`
already renders in that user's own admin UI language, automatically, with no per-notification
change needed** — Laravel's `NotificationSender` consults the contract for every `notify()` call
through a model.

That automatic resolution does **not** extend to `Illuminate\Notifications\AnonymousNotifiable`
(`Notification::route('mail', $address)->notify(...)`), which every future notification needs
whenever the recipient is not yet a resolvable `User` row at the address being mailed — most
commonly an unverified new email mid-change. `AnonymousNotifiable` does not implement
`HasLocalePreference`, so without an explicit call the notification renders in whatever locale
happens to be ambient on the current process (an administrator's, on an admin-initiated action),
not the intended recipient's. `App\Actions\Users\RequestEmailChange` is the shape to copy:

```php
// app/Actions/Users/RequestEmailChange.php
Notification::route('mail', $newEmail)
    ->notify((new PendingEmailVerification($user, $newEmail))->locale($user->preferredLocale()));
```

✅ Good — `$user->notify(new UserInvitation(...))` (picks up locale automatically); or, for an
`AnonymousNotifiable` recipient, `(new SomeNotification(...))->locale($user->preferredLocale())`
chained onto the `notify()` call, exactly as `RequestEmailChange` does.
❌ Bad — `Notification::route('mail', $address)->notify(new SomeNotification(...))` with no
`->locale(...)` call. It compiles, sends, and passes a test that only checks the mail was queued —
the bug is silent until someone reads the rendered copy in the wrong language.

## Rule 2 — Admin dashboard code must not assume a fixed rendering locale

`App\Http\Middleware\SetUiLocale` runs in the `web` middleware group on **every** request,
including a Livewire `/livewire/update` round-trip, and resolves it from the signed-in
administrator's own `ui_locale` (falling back through `LocaleSetting::defaultUiLocale()`). That
means `App::getLocale()` is **not** a fixed value for the lifetime of the app — it can differ
between two requests hitting the same route, one second apart, depending only on who is signed in.

**Any new admin-dashboard Blade view, Livewire component, or Flux UI screen must route every
user-facing string through `trans()`/`__()` against a real `lang/en/<domain>.php` /
`lang/es/<domain>.php` key pair — never a hardcoded literal English string in the view or the
component class.** For how to name and structure that key (domain file, `snake_case` leaf,
`trans_choice()` for plurals, the registry-mirroring shape), see
[naming/translation-keys-and-booleans.md](naming/translation-keys-and-booleans.md) — this doc does
not repeat those rules, only the "must go through `trans()` at all" requirement that precedes them.

Real, shipped precedent for the ✅ shape: [`lang/en/topbar.php`](../../lang/en/topbar.php) (story
0057a) supplies every screen's heading/subheading as a domain key, and
[`lang/en/users.php`](../../lang/en/users.php) does the same for that screen's own copy — both with
a key-for-key `lang/es/` counterpart.

**This repo's own admin screens are not yet fully compliant with this rule, and that gap is not a
pattern to copy going forward** — recorded as story 0066's own D-10: `lang/{en,es}/` today covers
most screens' own domain copy (`users`, `roles`, `navigation`, `sales-regions`, `media`,
`customers`, `products`, `orders`, and others, one file per feature area) plus every screen's
title/subtitle via `topbar.php`, but shared modal/table chrome on some older screens still calls
`__()` with the literal English string as its own key and no backing catalog entry — for example
[`resources/views/livewire/users.blade.php`](../../resources/views/livewire/users.blade.php)'s
`{{ __('Cancel') }}`, `{{ __('Save') }}` and `:label="__('Name')"`. Whether such a bare
literal translates depends on a hidden `lang/es.json` entry: `"Cancel": "Cancelar"` exists
there, so that one does translate, while a literal with no JSON entry simply returns itself
unchanged under `App::setLocale('es')` — it does not error, so the gap is invisible except by
reading the rendered screen. A bare literal that happens to be in `es.json` is still the wrong
pattern: the translation lives in a catalog nobody reviews per feature, and the same literal can
map differently by surface (`Dashboard` → `Panel` there, `Inicio` via `topbar.dashboard.title`). Do not extend this pattern to new code; a new string gets a real domain-file key from the
first commit that adds it, not a bare `__('<the English words>')` call. The Settings navlist
(`resources/views/components/settings/layout.blade.php`) is the worked example of the migration:
story 0067 moved its tab labels from `__('Profile')` etc. to `topbar.settings.*` keys.

✅ Good — `:label="__('users.fields.name')"` with `'name' => 'Name'` under a `fields` group in
`lang/en/users.php` / `lang/es/users.php`.
❌ Bad — `:label="__('Name')"` with no corresponding `lang/` entry for the literal `'Name'` — renders
correctly in English only by coincidence (the key equals the fallback copy) and never translates.
(`__('Cancel')` is *not* a good counter-example of "never translates": `lang/es.json` maps it.)

_Last updated: 2026-10-02 — story 0067. Rule 2's `__('Cancel')` example corrected (`lang/es.json` does translate it; the real hazard is an unreviewed JSON catalog and per-surface divergence) and the keyed Settings navlist added as the migration example. Original forward-looking rules (explicit `->locale()` on an `AnonymousNotifiable` notify() call, `trans()`-backed copy for new admin strings, the Layer 1/Layer 2 boundary) are unchanged._
