# [0067] Admin UI language switcher — frontend

## Description
Let an administrator choose the **interface language** (Spanish or English) from **two surfaces**: a
switcher in the dashboard chrome's account menu, and a dedicated **Language tab in the account
Settings area** beside Profile / Security / Appearance. Both write through the same
`App\Actions\Users\SetUserUiLocale` and share one locale-resolution implementation, so they cannot
drift. This is the UI half of [PRD](../../../docs/PRD/sections/epic-5-internationalization.md#epic-5--internationalization) Epic 5's
**Layer 1 — Admin UI language switcher**; the storage, the offered pair and the per-request
resolution are sibling story **0066**'s contract, which this story **consumes and never re-derives**.
Strictly Layer 1: no relationship to Layer 2's `store_languages` catalog (story 0068), which the PRD
warns must not be conflated with it.

## Type
frontend | includes database-expert: no | related backend stories: 0066 and 0068 (**both shipped**, in `done/` — see **R-1**)

## Gherkin

```gherkin
Feature: Admin UI language switcher (Layer 1)

  Scenario: An administrator switches the interface language to English from the account menu
    Given a signed-in administrator using the interface in Spanish
    When they choose English from the account menu's interface language switcher
    Then the interface language labels that have translations are shown in English

  Scenario: An administrator switches the interface language back to Spanish from the account menu
    Given a signed-in administrator who has just switched the interface to English
    When they choose Spanish from the account menu's interface language switcher
    Then the interface language labels that have translations are shown in Spanish

  Scenario: An administrator switches the interface language from the Settings area
    Given a signed-in administrator using the interface in English
    When they choose Spanish on the Settings area's Language tab
    Then the interface language labels that have translations are shown in Spanish

  Scenario: The Settings area offers a Language tab
    Given a signed-in administrator viewing their account settings
    When they look at the settings navigation
    Then a Language tab is listed alongside Profile, Security and Appearance

  Scenario: A choice made in the account menu is reflected on the Settings tab
    Given a signed-in administrator who has just chosen Spanish from the account menu
    When they open the Settings area's Language tab
    Then Spanish is shown as the currently selected option

  Scenario: Each surface offers only Spanish and English
    Given a signed-in administrator
    When they open either interface language surface
    Then exactly Spanish and English are offered
    And no store content language appears among the options

  Scenario: The switcher indicates the language currently in use
    Given a signed-in administrator whose interface language preference is Spanish
    When they open the account menu's interface language switcher
    Then Spanish is shown as the currently selected option

  Scenario: An administrator who has never chosen a language sees the store default selected
    Given a signed-in administrator who has never chosen an interface language
    When they open the account menu's interface language switcher
    Then the store's default dashboard language is shown as the currently selected option

  Scenario: The choice outlives the session it was made in
    Given an administrator who chose Spanish and has since signed out
    When they sign in again in a new session
    Then the interface language labels that have translations are shown in Spanish

  Scenario: The switcher is available to an administrator holding no module permissions
    Given a signed-in administrator holding no module permissions
    When they open the account menu
    Then the interface language switcher is available to them

  Scenario: The Settings Language tab is available to an administrator holding no module permissions
    Given a signed-in administrator holding no module permissions
    When they open the Settings area's Language tab
    Then the tab is served to them normally

  Scenario: The switcher is available on a narrow viewport
    Given a signed-in administrator using a narrow viewport
    When they open the account menu
    Then the interface language switcher is available to them

  Scenario: A visitor cannot reach the Settings Language tab
    Given a visitor who is not signed in
    When they request the Settings area's Language tab
    Then they are redirected to the sign-in page

  Scenario: The Settings navigation is shown in the administrator's interface language
    Given a signed-in administrator whose interface language preference is Spanish
    When they open their account settings
    Then the settings navigation lists Perfil, Seguridad, Apariencia and Idioma

  Scenario: A language outside the offered pair is refused
    Given a signed-in administrator whose interface language preference is Spanish
    When they submit an interface language outside the offered pair
    Then the change is refused
    And their stored interface language preference is unchanged
```

> **Deliberately absent:** there is **no** scenario asserting *"the menus, labels, and buttons are
> shown in English"* as a claim about the **whole** interface, even though
> [PRD](../../../docs/PRD/sections/epic-5-internationalization.md#epic-5--internationalization) Layer 1's own Gherkin says exactly that.
> *(Corrected in correction pass 2, Phase 2 re-review RR-1.)* Measured at HEAD: every bare-literal
> `__('…')` in `resources/views` already has a Spanish entry in `lang/es.json` (commit 152e9a9), e.g.
> `Settings` → `Ajustes`, `Log out` → `Cerrar sesión`, `Profile` → `Perfil`, `Security` →
> `Seguridad`, `Appearance` → `Apariencia`, `Dashboard` → `Panel`. So the account menu and the current
> settings navlist **already render in Spanish** for a Spanish user. The text that stays English in both
> locales is the page `<title>`, built from the components' bare `#[Title('…')]` attribute literals
> (e.g. `Profile settings`), which `partials/head.blade.php` prints without `__()` (**R-5**). This story
> does **not** fix that and does not claim the whole interface is translated, so the PRD clause is
> **not** checked off. The scenarios above are scoped to *"labels that have translations"*. What this
> story *does* change is the Settings navlist, which moves onto the `topbar.settings.*` domain keys
> (C-3 option (a), **D-21**) and gains the Language tab. See **D-11**, **D-21**, **R-5**.

## Files to create/modify

### Surface 1 — the chrome switcher

**Livewire component** — class-based, per
[base-standards.md](../../../docs/conventions/base-standards/livewire-and-flux-conventions.md#livewire-component-convention-class-based-not-single-file)

- `app/Livewire/Settings/LanguageSwitcher.php` — new.

  ```php
  namespace App\Livewire\Settings;

  class LanguageSwitcher extends Component
  {
      use InteractsWithUiLocale;   // D-17 — currentLocale() lives here, shared
      use UserValidationRules;     // 0066's uiLocaleRules()

      public function setLocale(string $locale, SetUserUiLocale $setUserUiLocale): void
      {
          // Validate BEFORE UiLocale::from() -- see D-19.
          Validator::make(
              ['ui_locale' => $locale],
              ['ui_locale' => $this->uiLocaleRules()],
          )->validate();

          $this->applyUiLocale($locale, $setUserUiLocale, $this->sameHostPreviousUrl());
      }

      // Phase 4 L-1 / Phase 5 item 4: the Referer is client-controlled, so only a same-host
      // previous URL is trusted; anything else (and "no previous URL") lands on the dashboard.
      private function sameHostPreviousUrl(): string
      {
          $previous = url()->previous(route('dashboard'));

          return parse_url($previous, PHP_URL_HOST) === request()->getHost()
              ? $previous
              : route('dashboard');
      }
  }
  ```

  *(Corrected after Phase 5.)* The earlier snippet used `url()->previous() ?: route('dashboard')`.
  That fallback could never fire, because `url()->previous()` never returns a falsy value: with no
  Referer and no session previous URL it returns `url('/')`, the public welcome page. It also trusted
  a foreign-host Referer as a redirect target (Phase 4 **L-1**, CWE-601 defense-in-depth).

  **No `wire:model`-bound property** (**D-3**). **Method injection** of the action (**D-6**).

- `resources/views/livewire/settings/language-switcher.blade.php` — new. **Nested**, per the
  *ordinary* kebab-case mirror rule — the class is not named `Index`, so the
  [`Index`-in-a-subfolder exception](../../../docs/conventions/naming/livewire-components-and-views.md#exception-a-component-named-index-resolves-to-its-parent-folders-name)
  does **not** apply. `App\Livewire\Media\Gallery` → `livewire/media/gallery.blade.php` is the
  precedent. **Do not create a flat `livewire/language-switcher.blade.php`.**

**Chrome — two render sites, not one** (**D-1**)

- `resources/views/components/desktop-user-menu.blade.php` — **modified**. The desktop account
  dropdown, rendered from `sidebar.blade.php:23` as
  `<x-desktop-user-menu class="hidden lg:block" … />`.
- `resources/views/layouts/app/sidebar.blade.php` — **modified**. Since story 0057a the layout has one
  persistent topbar (`data-test="topbar"`); inside it, the mobile account dropdown (lines ~54–102,
  inside `<div class="lg:hidden" data-test="topbar-mobile-profile">`) is a **second, hand-duplicated**
  dropdown that does *not* include `<x-desktop-user-menu />`. A switcher added only to the component
  above is **invisible on mobile**. This edit also adds `data-test="mobile-menu-button"` to that
  dropdown's `<flux:profile>` trigger (line ~56), which has none today (**D-14**).

### Surface 2 — the Settings Language tab (**D-16**)

- `app/Livewire/Settings/Language.php` — new. `#[Title('Language settings')]`, a bare English literal
  matching `Profile`'s `#[Title('Profile settings')]` and `Appearance`'s verbatim (neither wraps it in
  `__()`). Uses the **same** trait and the **same** action; differs only in its redirect target
  (**D-18**).
- `resources/views/livewire/settings/language.blade.php` — new. Follows its three siblings exactly:
  `<x-slot:heading>{{ __('topbar.settings.language') }}</x-slot:heading>` and
  `<x-slot:subheading>{{ __('topbar.settings.language_subtitle') }}</x-slot:subheading>` (forwarded to
  the topbar), then `<x-settings.layout>` with **no** heading props, rendering the two options in a
  settings-form idiom (Appearance's `flux:radio.group variant="segmented"` is the visual sibling)
  rather than the chrome's menu-item idiom.
- `routes/settings.php` — **modified**. One route inside the **existing `auth` + `verified` group**,
  plus its `use` import:

  ```php
  Route::livewire('settings/language', Language::class)->name('language.edit');
  ```

- `resources/views/components/settings/layout.blade.php` — **modified, four navlist item lines plus one
  test hook**: a fourth item added, the three existing labels switched from bare literals to the
  existing `topbar.settings.*` keys (**D-21**, Phase 2 C-3 option (a), decided by the human), and
  `data-test="settings-navlist"` added to the `<flux:navlist>` opening tag so navlist assertions can be
  scoped (correction pass 2):

  ```blade
  <flux:navlist aria-label="{{ __('Settings') }}" data-test="settings-navlist">
  <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('topbar.settings.profile') }}</flux:navlist.item>
  <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('topbar.settings.security') }}</flux:navlist.item>
  <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('topbar.settings.appearance') }}</flux:navlist.item>
  <flux:navlist.item :href="route('language.edit')" wire:navigate>{{ __('topbar.settings.language') }}</flux:navlist.item>
  ```

  Nothing else in that file changes. The navlist's `aria-label="{{ __('Settings') }}"` keeps its value;
  it already renders `Ajustes` in Spanish through `lang/es.json`.
- `lang/en/topbar.php`, `lang/es/topbar.php` — **modified**. The `settings` group gains
  `language` (`'Language'` / `'Idioma'`) and `language_subtitle` (e.g. `'Choose the language of the
  administration interface'` / `'Elige el idioma de la interfaz de administración'` — final copy
  confirmed in Phase 3), key-for-key identical in both files. `profile` / `security` / `appearance`
  already exist in both files and are **reused, not re-added**.

### Shared between both surfaces (**D-17**)

- `app/Concerns/InteractsWithUiLocale.php` — new. The **single** implementation of the current-value
  resolution and the persist-then-redirect behaviour:

  ```php
  trait InteractsWithUiLocale
  {
      #[Computed]
      public function currentLocale(): string
      {
          return UiLocale::tryFrom((string) Auth::user()->ui_locale)?->value
              ?? LocaleSetting::defaultUiLocale()->value;   // D-4 -- mirrors the middleware exactly
      }

      protected function applyUiLocale(string $locale, SetUserUiLocale $setUserUiLocale, string $redirectTo): void
      {
          $setUserUiLocale(UiLocale::from($locale));

          $this->redirect($redirectTo);
      }
  }
  ```

### Shared enum and translations

- `app/Enums/UiLocale.php` — **modified** (owned by story **0068**, which moved it from 0066), gaining
  `label(): string` and nothing else. The *second consumer* [naming.md](../../../docs/conventions/naming/translation-keys-and-booleans.md#translation-keys)'s "add `label()`
  when a second consumer appears" rule anticipates; it was deferred here explicitly. Returns
  `'English'` / `'Español'` — **not** via `__()` (**D-9**). No new case, no `default()`. The enum's
  docblock paragraph *"Deliberately no `label()` method -- no rendering site exists yet…"* must be
  **rewritten** in the same edit to state that `label()` now exists and why it returns endonyms; the
  *"Deliberately no `default()`"* paragraph stays.
- `lang/en/localization.php`, `lang/es/localization.php` — **modified** (both already exist, shipped
  **empty** by story 0068 with a header comment reserving them for story **0069**'s `attributes` block
  and `settings.*` keys). This story adds a `switcher` group with one key (`switcher.heading` →
  `'Language'` / `'Idioma'`) used by the **chrome** dropdown only, key-for-key identical in both files,
  and updates the reserving header comment in both files to record that the `switcher.*` group belongs
  to 0067 while `attributes` / `settings.*` remain reserved for 0069. ⚠️ **Same-file conflict risk with
  0069**: both stories edit these two files and their header comment; whichever lands second must
  rebase and merge the comment and the array by hand rather than overwrite. Deliberately **not** added
  to `lang/{en,es}/navigation.php`, whose purpose is mirroring `config/modules.php`'s registry keys
  (**D-10**). The Settings tab's own keys live in `topbar.php` (above), not here (**D-21**).

### Existing tests modified (listed after Phase 5)

- `tests/Unit/Enums/UiLocaleTest.php` — **modified**: 0068's "no `label()`" assertion is rewritten
  to assert `label()` returns `English` / `Español` while `default()` still does not exist.
- `tests/Feature/Layout/TopbarTest.php` — **modified**: `language.edit` is added to the
  `topbarScreens()` dataset.

The new test files are listed under [Tests to perform](#tests-to-perform).

**Not touched by this story** — see [Scope fences](#scope-fences-what-this-story-must-not-do).

## Tests to perform

> **Read this first: `Livewire::test()` cannot prove this story's user-visible outcome, on either
> surface.** 0066 verified against installed Livewire v4.3.3 that `Testable` routes both the initial
> render and every `->call()`/`->set()` through
> `RequestBroker::temporarilyDisableExceptionHandlingAndMiddleware()`, whose body calls
> `->withoutMiddleware()`. So `App\Http\Middleware\SetUiLocale` **never runs** under a component test,
> and "after calling the switcher, the UI is in Spanish" is vacuously green even with the middleware
> deleted. Component tests below cover **only** validation and delegation.

**Browser — `tests/Browser/Localization/AdminUiLanguageSwitcherTest.php`** (new — the **chrome**
surface; mirrored-subfolder convention, **D-12**)

- [ ] **Round trip ES → EN → ES** from the account menu. Click, force a fresh page load, assert;
      click back, force a fresh load, assert. *Risk if missing:* a switcher wired on only one of the
      two options passes a one-direction test and ships dead for half the userbase — and English is
      the developer's own working language, so ES→EN is the direction that gets hand-tested.
- [ ] **Exactly two options**, scoped to `data-test="language-switcher"`, asserting the option
      **value set equals `{en, es}`** — see the ⚠️ in **D-20** before writing the assertion.
- [ ] **The active option is indicated**, matching the stored preference.
- [ ] **`ui_locale = null` renders a working control** with the store default selected, plus
      `assertNoJavaScriptErrors()`. *Risk if missing:* **the likeliest bug in the story** —
      `UiLocale::from($user->ui_locale)` throws on `null`, the state of **every account on first
      deploy**, since 0066 ships the column nullable with no backfill (**D-4**).
- [ ] **Available to an administrator holding no module permissions** (a factory user with no role).
      *Risk if missing:* this codebase gates nearly every other surface, so reflexively wrapping the
      control in `@can(...)` is a plausible silent regression hiding a self-service control from the
      staff least able to work around it (**D-8**).
- [ ] **Available on a narrow viewport.** *Risk if missing:* the live layout has **two** account
      dropdowns and the desktop one is `hidden lg:block`; a single-site implementation strands mobile,
      and no desktop-width test can see it (**D-1**).
- [ ] **Full journey persistence** — sign in via the real login form, switch, sign out via
      `data-test="logout-button"`, sign back in, assert. Exactly **one** instance (**D-15**).
      ⚠️ `data-test="logout-button"` exists in **both** account menus (`desktop-user-menu.blade.php`
      and the mobile dropdown in `sidebar.blade.php`), and both are in the DOM at once (only
      CSS-hidden), so the logout click **must be scoped to one menu** — e.g. inside the open desktop
      dropdown at desktop width — or it hits the duplicate-hook trap in
      [waiting-rules.md](../../../docs/testing/frontend/playwright-setup/waiting-rules.md#a-page-embedding-the-same-component-twice-duplicates-every-data-test-hook)
      (Phase 2 C-8). The same applies to every chrome-switcher hook, which this story renders twice.

**Browser — `tests/Browser/Settings/LanguageTest.php`** (new — the **Settings** surface; mirrors the
app structure, **D-22**)

- [ ] **Switching from the Settings tab changes the language.** Click the option, allow the redirect,
      assert the translated string. **One direction is enough** — the chrome test already proves the
      underlying mechanism is symmetric, and both surfaces share one `applyUiLocale()` (**D-17**), so
      a second full round trip here buys nothing.
- [ ] **Exactly two options on this surface too**, scoped to `data-test="settings-language-switcher"`.
      This is **not** a duplicate of the chrome assertion: the settings view is a *different markup
      family* (a settings-form idiom, not menu items), so it is a second implementation of the same
      constraint (**D-20**).
- [ ] **The active option is indicated on this surface**, read through the shared `currentLocale()`.
      *Risk if missing:* the trait guarantees one *implementation* of the read, but not that this
      view actually **calls** it — a view hardcoding the selected state would pass every other test
      here (**D-17**).
- [ ] **Cross-surface reflection**: switch from the chrome, then open the Settings tab and assert the
      new value is shown selected. One test, one direction. Cheap precisely **because** the trait
      makes logic drift structurally unlikely — what this guards is the *wiring*, not the logic
      (**D-17**).
- [ ] ⚠️ **Hook-collision safety** — every assertion on this page must be scoped to the
      settings-surface hooks, because the chrome switcher **co-renders here** (**D-20**).

**Feature — `tests/Feature/Settings/LanguageTest.php`** (new; per-screen naming, matching the real
`tests/Feature/Settings/` convention — `EmailChangeTest`, `ProfileUpdateTest`, `SecurityTest`)

- [ ] **A guest is redirected to `login`.** `$this->get(route('language.edit'))->assertRedirect(route('login'))`.
      The one real route-level control.
- [ ] **A signed-in user holding zero module permissions gets 200.**
- [ ] **The Language tab renders in the settings navigation** — assert from an *existing* settings
      page (`route('profile.edit')`), since that is where a user actually encounters the link.
- [ ] **The settings navlist labels render in Spanish for a Spanish-preference user.** A plain HTTP
      `$this->actingAs($esUser)->get(route('profile.edit'))` runs the real `web` middleware stack, so
      `SetUiLocale` **does** run here (unlike `Livewire::test()`). Assert `Perfil`, `Seguridad`,
      `Apariencia` and `Idioma` in order, and assert the English labels are absent. Pair this with the
      English user case (`Profile` / `Security` / `Appearance` / `Language`).
      ⚠️ **Every assertion, positive and negative, must be scoped to `data-test="settings-navlist"`**
      (extract that element from the response HTML, or use a browser test's scoped locator); a
      page-global `assertSee`/`assertDontSee` is **not acceptable**. Unscoped, the strings collide with
      other page text: the untranslated `<title>` (`Profile settings`), the topbar heading
      (`topbar.settings.profile` → `Perfil`), the chrome switcher's `Language`/`Idioma` heading, and
      the account menu.
      ⚠️ **What red-before-implementation proves here:** `lang/es.json` already translates
      `Profile` / `Security` / `Appearance`, so before implementation the Spanish case goes red
      **only on `Idioma`**; the other three labels are already green through the JSON literals. Their
      green therefore does **not** prove the switch to `topbar.settings.*` keys. That switch is
      checked by code review of the four lines, and a key typo (`topbar.settings.securty`) still turns
      the test red because the raw key renders (**D-21**).
- [ ] ⚠️ **Do NOT write a `verified`-refusal test.** `App\Models\User` does not implement
      `MustVerifyEmail`, so `verified` refuses **nobody** on any route in this app — a test asserting
      it cannot go red however it is written. This is a recorded, previously-paid-for lesson
      ([errors-log.md](../../../docs/errors-log/archive-2026-08-17-to-2026-08-21.md#a-planned-test-asserted-a-refusal-by-verified-a-middleware-that-refuses-nobody-in-this-app--2026-08-20)).

**Feature — `tests/Feature/Localization/LanguageSwitcherTest.php`** (new; the shared behaviour, tested
once)

- [ ] A forged locale (`'fr'`, `'EN'`, `'en-US'`, `''`) via
      `Livewire::test(LanguageSwitcher::class)->call('setLocale', …)` is refused and **the database
      value is unchanged**. Assert the row, **not** an error message — see **D-19** for why the
      message is deliberately not assertable here.
- [ ] The same refusal against `Livewire::test(Language::class)` — the settings component. Both
      surfaces are independently reachable over `/livewire/update`, so both need the guard proven.
- [ ] `currentLocale()` returns the store default for `ui_locale = null`, and for a stale stored value
      written past the model with `DB::table('users')->update(['ui_locale' => 'fr'])` (the model layer
      would refuse to create that fixture). Arrange the `locale_settings` row with `updateOrCreate`,
      never `factory()->create()` — 0068's singleton has a fixed primary key.
- [ ] **Chrome redirect target, three tests** (added after Phase 4 L-1 / Phase 5 item 4, **D-18**):
      a foreign-host previous URL never becomes the redirect target (it redirects to
      `route('dashboard')`); a same-host previous URL is returned to; and with no previous URL the
      redirect is `route('dashboard')`, not the public welcome page.

**Existing tests modified by this story** (added to this list after Phase 5; it was missing before)

- [ ] `tests/Unit/Enums/UiLocaleTest.php` — **modified**. 0068's test "the enum declares no label method
      and no default method" pinned `label()` as absent, so it turns red once this story adds
      `label()`. Rewrite it to assert that `label()` returns `English` / `Español` and that `default()`
      still does not exist.
- [ ] `tests/Feature/Layout/TopbarTest.php` — **modified**. Add
      `'language settings' => ['language.edit', 'topbar.settings.language']` to the `topbarScreens()`
      dataset. Its "every authenticated app screen is covered" test fails otherwise, and the entry
      gives the Language screen the same topbar title/subtitle coverage as its siblings.

**Prove-it-can-fail steps (mandatory before the assertions are trusted)**

- [ ] **Exactly-two-options**: render a third decoy option, confirm **red**, revert. Required by this
      repo for any count/set assertion after the `<ui-checkbox-group>` over-count incident.
- [ ] **Round trip**: make `applyUiLocale()`'s action call a no-op, confirm the chrome browser test
      goes **red**, restore. The browser-level equivalent of 0066's "move the middleware off the `web`
      group and confirm red".
- [ ] **Hook collision** (**D-20**): temporarily give the settings surface the chrome's *unsuffixed*
      hooks, re-run the settings browser test, and confirm it either errors on an ambiguous locator or
      — worse — **passes while driving the chrome control**. Verify which by additionally no-op'ing
      the chrome control and seeing whether the test then fails. Restore the suffixed hooks. Skipping
      this leaves open exactly the failure it guards: a green test that never touched the surface it
      claims to.

**Explicitly not tested** (per [what-not-to-test.md](../../../docs/testing/qa/what-not-to-test.md))

- Everything in 0066's `UiLocaleResolutionTest` / `UiLocaleLivewireRoundTripTest` /
  `SetUserUiLocaleTest` / `PreferredLocaleTest` — guest fallback, leak-forward, stale stored values,
  the Livewire round-trip, the action's cross-user refusal and notification locale are proven there.
- **A second full sign-out/sign-in journey through the Settings surface** — 0066 proves persistence at
  the HTTP layer and this story spends its **one** accepted browser journey on the chrome (**D-15**).
- **A second round-trip direction on the Settings surface** — shared `applyUiLocale()` (**D-17**).
- `App::setLocale()`, `Rule::enum()`, Flux and Livewire internals — vendor behaviour.
- **Full UI translation coverage** — out of scope by **D-11**/**R-5**; only the settings navlist
  (**D-21**) is asserted, because this story changes it.
- Firefox/WebKit — this repo verifies Chromium only.
- Step-up/password confirmation — the Settings route deliberately carries no `password.confirm`
  (**D-16**); do not invent a requirement it does not have.

## Expected outcome
Every signed-in administrator can set their interface language from **two** places — the account menu
on any screen, and a dedicated Language tab in Settings — each offering exactly Spanish and English
and showing which is active. Either surface persists the choice to their own `users` row through
`SetUserUiLocale` and re-renders the panel so that every string which **has** a translation appears in
the chosen language, verifiably the sidebar navigation and the Settings area's four tab labels (which
this story moves onto the existing `topbar.settings.*` keys). The choice survives sign-out and
sign-in, and a change made on one surface is reflected on the other. The account menu's `Settings` /
`Log out` and the other bare-literal `__()` strings were already translated through `lang/es.json` and
switch too. The page `<title>`, built from untranslated `#[Title('…')]` attribute literals, stays
English in both locales; this story does not change that and does not claim to (**R-5**).

## Acceptance criteria
- [x] A language control renders in the account menu for **every** authenticated user, on both desktop
      and narrow viewports, with no permission check anywhere in its path.
- [x] A **Language tab** renders in the settings navigation beside Profile / Security / Appearance, and
      `GET settings/language` serves it to any authenticated user and redirects a guest to `login`.
- [x] All four settings navlist labels and the Language screen's heading/subheading resolve through
      `topbar.settings.*` keys present in both `lang/en/topbar.php` and `lang/es/topbar.php`; no new
      bare `__('…')` literal is introduced (localization.md Rule 2). `<flux:navlist>` carries
      `data-test="settings-navlist"`, and every navlist label assertion is scoped to it.
- [x] Each surface offers **exactly** Spanish and English, and no store content language.
- [x] Each surface indicates which language is currently in effect, resolved through the **one shared**
      `currentLocale()` — `tryFrom(...) ?? LocaleSetting::defaultUiLocale()->value`, never `from()`,
      and never a second copy of the expression.
- [x] Both surfaces persist via `App\Actions\Users\SetUserUiLocale`; neither writes `users.ui_locale`
      directly, and neither re-implements the offered pair.
- [x] After choosing on either surface, the administrator sees translated labels change **without
      manually reloading**; the chrome returns them to the page they were on when that previous URL is
      on this host, and to the dashboard otherwise (a foreign-host Referer, or no previous URL); the
      Settings tab stays on itself.
- [x] A value outside `en`/`es` submitted to **either** component is refused and leaves the stored
      value unchanged.
- [x] An account with `ui_locale = null` renders both surfaces correctly with the store default
      selected, and raises no error.
- [x] The two surfaces carry **distinct** `data-test` hooks, so a test on the settings page can target
      each unambiguously despite the chrome switcher co-rendering there.
- [x] No `config/modules.php` entry, no permission, no migration, no change to
      `sidebar-nav.blade.php`, and no `password.confirm` on the new route.

## Definition of Done
- [x] Tests written and green, plus the full existing suite (per
      [contracts.md](../../../docs/contracts.md)'s Full Test Suite Gate Rule).
- [x] All **three** quality gates run **unscoped** and each result recorded explicitly, including any
      not run: `php artisan test` (not `--filter`), `vendor/bin/pint --format agent` (not `--dirty`),
      and **Larastan level 7** (`vendor/bin/phpstan analyse`)
      ([errors-log.md](../../../docs/errors-log/archive-2026-08-23-to-2026-08-26.md#a-verification-record-that-lists-two-of-three-quality-gates-is-a-record-of-two-gates--2026-08-26)).
- [x] All **three** prove-it-can-fail steps performed and their red result recorded.
- [x] Code reviewed (code-reviewer).
- [x] No security findings (appsec-auditor).
- [x] Documentation updated (docs-keeper): `docs/api/routes.md` gains the **`language.edit` route row**,
      and `App\Livewire\Settings\LanguageSwitcher` gets a **row in the same routeless-component table as
      `App\Livewire\Notifications\Bell`** (~line 153: mounted from `desktop-user-menu.blade.php` and the
      mobile dropdown in `sidebar.blade.php` — **twice** per page, so its hooks are duplicated — route
      none, gate `auth` only); `InteractsWithUiLocale` is added to
      [stack-and-model-conventions.md#behavioural-traits-in-appconcerns](../../../docs/conventions/base-standards/stack-and-model-conventions.md#behavioural-traits-in-appconcerns)
      and to `docs/conventions/directory-structure/app-layers.md`'s `app/Concerns/` entry (that folder is
      already documented as holding behavioural traits — no widening needed);
      `docs/conventions/naming.md` records `UiLocale::label()` arriving as the anticipated second
      consumer, the `localization.php` `switcher.*` group as a non-registry-mirror lang group, and the
      `InteractsWith*` trait-naming precedent.
- [x] **This story does NOT claim the interface is fully translated.** It delivers two controls,
      translates the Settings navlist labels (**D-21**), and proves locale resolution reaches the
      render for strings that have translation keys. The PRD's *"the menus, labels, and buttons are
      shown in English"* clause is **not** satisfied here and must not be checked off (**D-11**,
      **R-5**).
- [x] Acceptance criteria met.

---

## 1. Refined user story

**As** an administrator using the Arospe backoffice,
**I want** to switch the panel's interface language between Spanish and English — quickly from the
account menu, or deliberately from my account settings —
**so that** I can work in my own language without asking anyone to change a setting for me, and
without re-selecting it every time I sign in.

This story delivers **two controls over one preference**. It does not deliver translated copy for the
parts of the interface that have none (**R-5**), and it does not deliver the storage or resolution
mechanism, which is story 0066's (**R-1**).

## 2. Detailed acceptance criteria (Given/When/Then)

See the [Gherkin](#gherkin) section — fifteen scenarios (the Spanish settings-navigation scenario was
added by the Phase 2 C-3 correction), each opening with a named business-role
actor and carrying exactly one `When`, per
[gherkin-guidelines.md](../../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3. The
deliberately-narrowed `Then` wording ("labels that have translations") is explained in the callout
beneath that block.

Terminology follows 0066's: **interface language** (Layer 1) throughout, never "store language"
(Layer 2, story 0068). 0066's Definition of Done already carries the follow-up adding both terms to
[gherkin-guidelines.md](../../../docs/testing/frontend/gherkin-guidelines.md)'s domain glossary, which
has a row for neither today; this story reuses the term rather than inventing a third spelling.

## 3. QA test cases / validation scenarios

See [Tests to perform](#tests-to-perform). The five that would otherwise pass for the wrong reason:

1. **Nothing about rendered language may be asserted through `Livewire::test()`** — it disables the
   middleware that resolves the locale, so such a test cannot fail.
2. **The `ui_locale = null` case is the story's likeliest real bug**, because it is the state of every
   existing account and the natural-looking `UiLocale::from(...)` throws on it.
3. ⚠️ **The `{en, es}` value-set assertion is BLIND to duplication** — the single sharpest finding of
   this round, and it inverts advice this file previously gave. Asserting the option *set* rather than
   a count was chosen so the test survives unrelated future controls. But the chrome switcher
   **co-renders on the settings page**, so a settings-page assertion sees `en`/`es` **twice** — and two
   duplicated pairs still reduce to the set `{en, es}`. The set comparison protects against an *extra*
   option and is defenceless against *the same options rendered twice*. Only **scoped, distinct hooks**
   plus a scoped count catch it (**D-20**).
4. **The round trip must force a fresh page load between click and assertion** — a partial Livewire
   re-render updates only the component's own subtree, so asserting on the same round-trip's DOM
   proves nothing about the sidebar (**D-5**).
5. **The forged-locale test must assert the database row, never an error message** — this component
   declares no bound property, so Livewire drops the error-bag entry entirely (**D-19**).

⚠️ **Two string-choice traps, verified in this repo rather than assumed** (**D-13**):

- `navigation.items.roles` is `'Roles & permissions'` / `'Roles y permisos'` — **both contain
  `Roles`**, so it cannot prove a switch happened.
- *(Historical — no longer applies at HEAD, Phase 2 C-7.)* This round originally flagged `'Dashboard'`
  as contaminated because `resources/views/dashboard.blade.php` passed a bare `__('Dashboard')` title.
  That file no longer exists: the dashboard is now `livewire/dashboard/overview`, titled through
  `topbar.dashboard.title` (`Dashboard` / `Inicio`), and `lang/es.json` maps the bare literal
  `Dashboard` → `Panel` (correction pass 2, RR-1). Avoid `'Dashboard'` anyway: its Spanish form differs
  by surface (`Inicio` / `Panel`), and the English string still appears in the untranslated
  `#[Title('Dashboard')]` page title. Use the safe pair below.
- ✅ **Safe pair:** `navigation.items.sales_regions` — `'Sales Regions'` / `'Regiones de venta'`, no
  overlap either way. Better still, scope to `data-test="sidebar-link-sales_regions"` (both re-verified
  at HEAD in Phase 2).

⚠️ **Waiting rule, load-bearing here rather than merely cited.** Both round-trip tests assert *after* a
navigation — exactly the shape that tempts `->waitForEvent('networkidle')`, the one call
[playwright-setup.md](../../../docs/testing/frontend/playwright-setup/waiting-rules.md#waiting-one-call-is-banned-in-this-repo-and-one-is-bounded)
**bans outright** (it never settles here; one session leaked ~60 `playwright run-server` processes and
OOM-killed the MySQL container). The accepted mitigation is a short **bounded** `->wait(n)` with an
inline comment stating what it compensates for — and before reaching for even that, check whether the
symptom is *"the click never registered"* (a compiled-Blade problem this repo has hit) rather than
timing.

## 4. Documented functional decisions

- **D-1 — The chrome switcher goes in the account dropdown, and that means editing TWO files.**
  ⚠️ **The two amigos contradicted each other and the contradiction is recorded rather than smoothed
  over, because one was working from dead code.** `frontend-qa` reported that `<x-desktop-user-menu />`
  renders once inside `<flux:header>`, is not viewport-gated, and so covers mobile "for free". That is
  **false for the live layout**, verified directly rather than by preferring an amigo:
  `resources/views/layouts/app.blade.php` binds **only** `x-layouts::app.sidebar`;
  `resources/views/layouts/app/header.blade.php` is referenced from **nowhere**
  (`grep -rn "app.header" resources/ app/` → no hits) and is dead starter-kit code — and it is the file
  `frontend-qa` traced. In the live `sidebar.blade.php`, `<x-desktop-user-menu class="hidden lg:block" />`
  (line 23 at HEAD) is **desktop-only**, and the mobile account menu is a **separate hand-duplicated**
  dropdown. *(Phase 2 C-1 update: the `<flux:header class="lg:hidden">` block this decision originally
  cited was replaced by story 0057a with one persistent topbar, `data-test="topbar"`; the mobile
  dropdown now sits at lines ~54–102 inside `<div class="lg:hidden" data-test="topbar-mobile-profile">`.
  The conclusion — two render sites — is unchanged.)* `frontend-expert` had this right. **So
  `frontend-qa`'s premise was wrong while its warning was right, and sharper than it realised.** Ship
  the control in both places. **Rejected:** refactoring the mobile block to reuse the component — the
  better end state, but it widens the diff into an unrelated cleanup.
- **D-2 — `App\Livewire\Settings\LanguageSwitcher` → `livewire/settings/language-switcher.blade.php`,
  nested.** Not named `Index`, so the `Index`-in-a-subfolder exception does not apply.
  [naming.md](../../../docs/conventions/naming/livewire-components-and-views.md#exception-a-component-named-index-resolves-to-its-parent-folders-name)
  flags this over-application as the live trap ("the exception keys on the class name, never on living
  in a subfolder"). **Resolve the view path by running the component**, and check no `artisan make:`
  scaffold has left a second unused stub at the wrong path — task 0017 hit that half.
- **D-3 — No `wire:model`-bound property; each option is a `wire:click` action.** Not a form collecting
  a pending choice but a list of act-now controls, the idiom every row action in this app already uses.
  It also sidesteps
  [the `null`-property/native-`<select>` failure class](../../../docs/errors-log/archive-2026-07-21-to-2026-08-17.md#a-null-livewire-property-bound-to-a-native-select-silently-dropped-the-users-own-pick--2026-08-16)
  **structurally rather than by discipline** — there is no bound property for a stale `null` to sit in.
- **D-4 — ⚠️ CORRECTED: the fallback is `LocaleSetting::defaultUiLocale()->value`, not
  `config('app.locale')`.** Read the current value with
  `UiLocale::tryFrom((string) $stored)?->value ?? LocaleSetting::defaultUiLocale()->value`, never
  `from()`. **This decision previously quoted `config('app.locale')` and that quote was stale** — 0066
  was substantially revised mid-Phase-1 and now delegates the fallback to story 0068's
  `LocaleSetting` singleton, with `config('app.locale')` demoted to a third tier reached only *inside*
  that accessor. The *principle* was already right and is what made the correction cheap: this
  expression is copied from the middleware **on purpose**, because two fallback expressions for one
  concept is the second-source-of-truth 0066's own D-6 refuses. `defaultUiLocale()` returns a
  `UiLocale`, never a string, so `->value` is required. `UiLocale::from(null)` is a `TypeError` and
  `from('fr')` a `\ValueError`, and `null` is the state of **every account on first deploy** since 0066
  ships the column nullable with no backfill. See **R-8** for how this correction was caught.
- **D-5 — Persisting is not enough; the story must force a fresh request.** `SetUiLocale` runs at the
  **top** of the very request the `wire:click` triggers, so it reads the **old** `ui_locale` and sets
  the old locale for that entire request; `SetUserUiLocale` writes afterwards. Worse, a normal Livewire
  round-trip re-renders **only the component's own subtree** — the sidebar is a plain Blade include
  outside it. The fix is a redirect after persisting, re-entering the middleware against the updated
  row.
  *(Phase 2 C-6 update.)* Verified in the installed vendor code: Livewire's
  `HandlesRedirects::redirect($url, $navigate = false)` performs a **hard** browser redirect by default
  (the component render is skipped unless `livewire.render_on_redirect` is set), which is the behaviour
  this decision needs — so call it **without** `navigate: true`. Still **confirm by the browser test**
  that the sidebar re-renders in the new language, per
  [the hedge rule](../../../docs/errors-log/archive-2026-08-23-to-2026-08-26.md#a-reviewers-correction-replaced-an-accurate-technical-explanation-with-a-wrong-one-unverified--2026-08-24).
- **D-6 — `SetUserUiLocale` is method-injected into `setLocale()`.** The
  [constructor-injection exception](../../../docs/conventions/code-style.md#exception-an-actions-own-dependency-is-constructor-injected-when-the-method-signature-is-a-public-contract)
  is for an *action* whose `__invoke()` is a public contract; a Livewire action method has no such
  contract. Do **not** reach for `app(...)`: its one licence here is a zero-parameter `#[Computed]`
  method, which `setLocale()` is not.
- **D-7 — Both components call the action; neither writes the column.** 0066 makes `SetUserUiLocale`
  the single writer and derives the self-only rule from `Auth::user()` internally, so neither surface
  passes a target argument.
- **D-8 — No permission gate, and no `config/modules.php` entry.** Setting your own interface language
  is self-service and identical for a roleless account and a Super Admin; 0066 introduces no policy and
  no permission, and neither does this story. The registry is for **permission-gated routes**, filtered
  through `Gate::any()` — the chrome switcher is not a route at all, and while the Settings tab *is* a
  route, it is an account-settings screen like Profile/Security/Appearance, none of which appear in the
  registry either. `sidebar-nav.blade.php` and `lang/{en,es}/navigation.php` stay untouched.
- **D-9 — The option labels are `'English'` and `'Español'`, hardcoded, not `__()` keys.** A language
  switcher lists each language **in its own language**, so a user who cannot read the current UI
  language can still find theirs. Translating them (Spanish chrome showing "Inglés") is wrong for
  *this* control. They live on `UiLocale::label()`, shared by both surfaces.
- **D-10 — A `switcher.*` group in the existing (empty, 0068-shipped) `lang/{en,es}/localization.php`,
  not `navigation.php`** *(Phase 2 C-2: the files already exist and are reserved for 0069; see the
  Files section for the comment update and the same-file conflict risk)*. `navigation.php` mirrors
  `config/modules.php`'s keys one-for-one; a non-registry key there breaks the one-identifier property
  that makes the registry reviewable.
- **D-11 — The outcome is two working controls plus *partial* visible translation, and it says so.**
  Verified: `<x-sidebar-nav />` resolves group headings and item labels through `__()` and
  `lang/es/navigation.php` is fully populated, so the sidebar genuinely switches. *(Corrected in
  correction pass 2, RR-1: the Phase 1 claim that the account dropdown's `__('Settings')` /
  `__('Log out')` have no translation and render identical English in both locales is **false** at
  HEAD. `lang/es.json` translates them to `Ajustes` / `Cerrar sesión`.)* The visible translation is
  still *partial*, because the page `<title>` comes from untranslated `#[Title]` literals (**R-5**).
  See **D-21** and **R-5**.
- **D-12 — `tests/Browser/Localization/AdminUiLanguageSwitcherTest.php` for the chrome surface.**
  [playwright-setup.md](../../../docs/testing/frontend/playwright-setup/status-structure-and-syntax.md#folder-structure) records that
  **a story file naming a test path is making a convention decision**, that the mirrored subfolder is
  the convention, and that the two flat files are *debt, not precedent*. The chrome switcher is not a
  screen, so a cross-cutting `Localization/` folder is right for it — mirroring 0066's own
  `tests/Feature/Localization/`.
- **D-13 — The assertion string is `sales_regions`, or a `data-test`-scoped selector.** Both amigos
  converged on the need for care and between them found the two traps recorded in §3. *(Correction
  pass 2, RR-1: `Dashboard` is **not** English-only — `lang/es.json` maps it to `Panel`, and the
  dashboard topbar title uses `topbar.dashboard.title` → `Inicio`. It is still a poor assertion string,
  because it differs by surface and also appears in the `#[Title('Dashboard')]` page title, which is
  not translated. Keep `sales_regions`.)*
- **D-14 — The mobile dropdown's trigger gains `data-test="mobile-menu-button"`.** The desktop trigger
  already carries `data-test="sidebar-menu-button"`; the mobile `<flux:profile>` (in
  `sidebar.blade.php` ~line 56, inside `data-test="topbar-mobile-profile"`) has none, so the
  narrow-viewport scenario would be hard to target. Scoping the click through the existing
  `topbar-mobile-profile` wrapper is an acceptable alternative if Phase 3 prefers not to add a hook. A hook added to make a required scenario reachable is
  part of the story, not scope creep.
- **D-15 — Keep exactly one full sign-out/sign-in browser journey**, on the chrome surface.
  `frontend-qa` flagged the cost/value tradeoff rather than assuming: 0066 proves persistence
  exhaustively at the HTTP layer, so this is the PRD's scenario acted out end to end. One instance is
  worth its cost; a second, on the Settings surface, would not be.

### Decisions added by the second surface

- **D-16 — The Settings tab is `GET settings/language`, named `language.edit`, in the existing
  `auth` + `verified` group.** ⚠️ **The amigos split and this resolves against `frontend-qa`.**
  `frontend-qa` recommended the bare `auth` group (matching `profile.edit`) on the grounds that it is
  the more honest signal for a screen with no permission check. `frontend-expert` recommended
  `auth` + `verified` (matching `appearance.edit`), and its reasoning is more specific and wins:
  `profile.edit` sits in the bare group **for a reason that does not apply here** — an unverified or
  pre-activation user must still reach their profile to drive the email-verification flow, which is
  exactly the deadlock `routes/settings.php`'s own comment describes for `email-change.confirm`. A
  language preference has no such role. `Appearance` — a self-service, identity-agnostic display
  preference — is the correct middleware sibling. **No `password.confirm`**: unlike `security.edit`
  there is no step-up-worthy write here. Both amigos independently noted, correctly, that the choice is
  *functionally* inert either way, since `verified` refuses nobody in this app — so this is a
  signalling decision, and it is recorded as one rather than presented as a behavioural one.
  Route naming follows the existing `<resource>.edit` shape, and `Language` is imported into
  `routes/settings.php` like its three siblings.
- **D-17 — The two surfaces share `App\Concerns\InteractsWithUiLocale`; they do not share markup, and
  they are not one embedded component.** ⚠️ **The amigos split here too, and this resolves against
  `frontend-qa`**, which recommended embedding `<livewire:settings.language-switcher />` in the
  settings page so there is literally one implementation. `frontend-expert` rejected embedding with two
  arguments, one of which is a plain fact about the shipped DOM rather than a matter of taste:
  **(a)** the chrome switcher's view is built to render *inside an open `<flux:menu>`*, so dropping it
  into `<x-settings.layout>`'s content slot is a structural mismatch against the form idiom every other
  settings tab uses; and **(b)** the chrome control already renders **twice per page** (desktop and
  mobile, both in the DOM, only CSS-hidden), so embedding a third instance as the page body would put
  **three simultaneous copies of one control on one screen**. That is decisive. A shared Blade partial
  (option b) was also rejected: the two surfaces plausibly want different Flux component *families*,
  and a partial emitting both would need internal branching that cancels the benefit. What is genuinely
  identical is the **data and the behaviour**, not the markup — so a trait carries `currentLocale()`
  and `applyUiLocale()`, and each component keeps its own thin `setLocale()` and its own view. This is
  also what keeps **D-4**'s single-source-of-truth property intact one level up: a second component
  re-deriving the fallback expression would recreate precisely the drift 0066's D-6 refuses, between
  two components instead of between a component and the middleware. `app/Concerns/` is the right home
  — this repo's existing "shared traits composed at the consumer" folder, which already holds
  behavioural traits (`HasTranslations`, `ChecksAbilitiesSafely`, `ResolvesFlagReasonLabel`,
  `ResolvesSalesRegionFromAddress`) alongside the `*ValidationRules` ones *(Phase 2 C-4: an earlier
  draft wrongly called this the folder's first non-validation trait)*. The docs pass only adds
  `InteractsWithUiLocale` to the existing behavioural-traits listings — see the DoD.
  ⚠️ **Sequencing:** build `LanguageSwitcher` consuming the trait **from the start**, in the same
  implementation pass — do not ship it with inline logic and treat extracting the trait as optional
  cleanup afterwards.
- **D-18 — The redirect target differs per surface, deliberately.** Chrome → the **same-host**
  previous URL, keeping the administrator where they were, since the switcher is reachable from any
  page. Concretely it is `url()->previous(route('dashboard'))`, used only when its host equals
  `request()->getHost()`; otherwise, including when there is no previous URL, the target is
  `route('dashboard')`. *(Corrected after Phase 5 item 4: the Phase 1 wording
  `url()->previous() ?: route('dashboard')` was dead code, since `previous()` never returns a falsy
  value and with nothing to go on it returns `url('/')`, the public welcome page outside the panel.
  The host check is Phase 4 **L-1**'s guard against redirecting to a forged foreign-host Referer.)* Settings → **`route('language.edit')`**, staying on the tab so
  the user sees their choice reflected, which is the contract every settings screen implies. It is a
  named route rather than `url()->previous()` for a second, defensive reason `frontend-expert` raised
  as an explicit hedge: every `flux:navlist.item` in the settings nav carries `wire:navigate`, and
  whether `Session::previousUrl()` updates as expected across a soft navigation is exactly the kind of
  vendor-internal claim this repo forbids asserting unverified. A named target sidesteps the question
  instead of betting on it. The target is passed as a parameter to `applyUiLocale()` precisely so the
  shared trait does not have to know which surface called it.
- **D-19 — Validation runs BEFORE `UiLocale::from()`, and the refusal is a guard rather than a
  user-facing message.** ⚠️ **This corrects the shape `frontend-expert` proposed.** Its snippet used
  `$this->validateOnly('locale', …)`, but Livewire's `validateOnly()` validates a **declared component
  property**, and **D-3** deliberately gives this component none — so that call has nothing to operate
  on. The correct shape is an explicit `Validator::make(['ui_locale' => $locale], ['ui_locale' =>
  $this->uiLocaleRules()])->validate()`. Two consequences worth stating rather than discovering:
  **(a)** the order is load-bearing — `UiLocale::from()` on an unvalidated forged value is a
  `\ValueError` and therefore a **500**, not a validation refusal, so validating first is what makes
  the story's own "a language outside the offered pair is refused" scenario true rather than a crash;
  and **(b)** because the component declares no bound property, Livewire's
  `SupportValidation::dehydrate()` filters the persisted error bag through `Utils::hasProperty()` and
  **drops the message entirely** — the same mechanism task 0017 recorded. That is *acceptable here and
  is not a defect to fix*: the only way to reach this branch is tampering, since the rendered control
  can emit nothing but `en` or `es`, so no legitimate user ever needs to read the message. It is,
  however, exactly why the test asserts **the database row** and not an error message.
- **D-20 — The two surfaces carry distinct `data-test` hooks, and the "exactly two options" assertion
  must be scoped and counted, not set-compared.** Chrome keeps `language-switcher` /
  `language-option-en` / `language-option-es`; the Settings surface uses
  **`settings-language-switcher` / `settings-language-option-en` / `settings-language-option-es`**.
  This is not tidiness. Verified independently: `config/livewire.php` sets
  `'component_layout' => 'layouts::app'`, no settings component overrides it with `#[Layout]`, and
  `layouts/app.blade.php` binds `x-layouts::app.sidebar` unconditionally — so **the chrome switcher
  co-renders on the Settings page**, and unsuffixed hooks would match **twice**. `frontend-qa` then
  found the consequence that actually matters and that **inverts this file's earlier advice**: the
  `{en, es}` *value-set* assertion, chosen so the test would survive story 0068 adding unrelated
  options, is **structurally blind to duplication**, because two identical pairs still reduce to the
  same set. A scoped **count** is what catches it. An ambiguous click target is the other half — it
  either errors loudly or, worse, silently drives the chrome control while the test claims to be
  proving the settings one. Hence the mandatory collision prove-it-can-fail step.
- **D-21 — ⚠️ REWRITTEN after Phase 2 (C-3, human decision: option (a)). All four settings navlist
  labels are keyed through `topbar.settings.*`, and the Language screen's heading/subheading use new
  `topbar.settings.language` / `language_subtitle` keys passed as `<x-slot:heading>` /
  `<x-slot:subheading>`.** The Phase 1 version of this decision ("keep the tab label, heading and
  subheading bare and keyless, matching the siblings") was **stale** against HEAD and **contradicted
  [localization.md Rule 2](../../../docs/conventions/localization.md#rule-2--admin-dashboard-code-must-not-assume-a-fixed-rendering-locale)**:
  every existing settings screen already passes `<x-slot:heading>{{ __('topbar.settings.<tab>') }}`
  and `<x-slot:subheading>{{ __('topbar.settings.<tab>_subtitle') }}` from `lang/{en,es}/topbar.php`
  (translated in Spanish), and only the three navlist labels were still bare JSON-literal keys
  (`__('Profile')` etc., which `lang/es.json` already translates). Rule 2 asks admin-dashboard code
  to use **domain keys** rather than bare JSON-literal keys, so a new `__('Language')` would be the
  wrong shape even with an es.json entry. Options considered by the human: **(a)** key the new item
  **and** switch its three siblings to the already-existing `topbar.settings.profile|security|appearance`
  keys, so the whole navlist uses the same domain keys as the screens' own headings — same file,
  three extra lines, no new copy (**chosen**); (b) key only the new item, leaving the navlist split
  between domain keys and JSON-literal keys; (c) a bare `__('Language')` plus an es.json entry as a
  documented Rule 2 exception. *(Correction pass 2, RR-1: an earlier wording justified (a) by claiming
  (b) would render "Profile / Security / Appearance / Idioma" in Spanish. That was false, because
  es.json already translates the three siblings. The justification is Rule 2's key shape, not a
  mixed-language navlist.)* Consequences: `settings/layout.blade.php` changes **four** navlist item
  lines plus a `data-test="settings-navlist"` hook (Files section); `topbar.php` gains two keys per
  locale; the three sibling *components* (`Profile`, `Security`, `Appearance`) and their views are
  untouched. The navlist's `aria-label="{{ __('Settings') }}"` is untouched and already renders
  `Ajustes` through es.json. `#[Title('Language settings')]` stays a bare literal — an attribute
  cannot call `__()`, and the siblings do the same. It is accepted here, but the resulting
  untranslated `<title>` is the real gap recorded in **R-5**. A Feature test asserts the navlist in
  both locales, scoped to the navlist hook (*Tests to perform*), and a Gherkin scenario covers the
  Spanish rendering.
- **D-22 — Test paths for the second surface: `tests/Feature/Settings/LanguageTest.php` and
  `tests/Browser/Settings/LanguageTest.php`.** The Feature path follows the real, verified convention
  in that folder — `EmailChangeTest.php`, `ProfileUpdateTest.php`, `SecurityTest.php`, per-screen
  naming with no `*RenderingTest` split. The browser path **resolves against `frontend-qa`**, which
  proposed `tests/Browser/Localization/SettingsLanguageSwitcherTest.php` as a sibling to the chrome
  test on "the technique differs materially" grounds. That reasoning is sound for the *chrome*
  switcher, which is not a screen — but the Settings tab **is** a screen backed by
  `App\Livewire\Settings\Language`, and
  [playwright-setup.md](../../../docs/testing/frontend/playwright-setup/status-structure-and-syntax.md#folder-structure) names
  **`tests/Browser/Settings/`** explicitly as an example of the mirrored structure it says is still the
  convention. Following the documented convention where it plainly applies beats extending a
  cross-cutting folder, especially in a repo that has recorded the mirrored convention "losing by
  default" twice. The two surfaces therefore sit in different folders **for a stated reason** — chrome
  is cross-cutting, the tab mirrors its component.

### Scope fences: what this story must NOT do

Stated in terms of **classes**, per the
[errors-log rule](../../../docs/errors-log/archive-2026-08-23-to-2026-08-26.md#a-scope-exclusion-named-screens-while-the-story-edited-a-class-those-screens-share--2026-08-24)
that a screen-shaped exclusion cannot bind shared code:

- `App\Http\Middleware\SetUiLocale` — **untouched**. Already global via 0066's `bootstrap/app.php`
  registration; this story registers no middleware and does not edit `bootstrap/app.php`.
- `App\Actions\Users\SetUserUiLocale` — **called from both surfaces, never modified**, and never passed
  a target argument.
- `App\Models\User` and `App\Models\LocaleSetting` — **untouched**. This story only *reads*
  `ui_locale` (through the shared trait) and *calls* `LocaleSetting::defaultUiLocale()`. It adds no
  column, no cast, no `#[Fillable]` entry, and does not touch `HasLocalePreference` or
  `preferredLocale()`, which are 0066's (**D-14** there).
- `App\Enums\UiLocale` (owned by 0068) — the single exception: `label()` is added (**D-9**), and the
  docblock's "Deliberately no `label()`" paragraph is rewritten to match. No new case, no `default()`.
- `App\Concerns\UserValidationRules` — **consumed, not modified**; `uiLocaleRules()` is 0066's.
- `App\Livewire\Settings\Profile`, `Security`, `Appearance` and their views — **untouched**. The
  settings *layout* (`resources/views/components/settings/layout.blade.php`) changes exactly **four
  navlist item lines** — one new item plus the three existing labels moved onto `topbar.settings.*`
  keys (**D-21**) — plus a `data-test="settings-navlist"` hook on `<flux:navlist>`, and nothing else
  (its `aria-label` keeps its value). None of the three sibling
  components changes, and `Appearance`'s client-side-only shape is explicitly **not** copied
  (**D-16**, **D-18**).
- `lang/{en,es}/topbar.php` — **only** the two new `settings.language` / `settings.language_subtitle`
  keys are added; no existing key is renamed or re-worded.
- `lang/{en,es}/localization.php` — **only** the `switcher.*` group and the header comment; nothing
  reserved for 0069 (`attributes`, `settings.*`) is created here.
- `config/modules.php`, `resources/views/components/sidebar-nav.blade.php`,
  `lang/{en,es}/navigation.php`, `database/**` — **untouched** (**D-8**).
- `resources/views/layouts/app/header.blade.php` — **untouched**. Dead code (**R-6**); editing it would
  create the illusion of coverage on a file nothing renders.
- `App\Livewire\Users\Index`, `Roles\Index`, `SalesRegions\Index`, `Media\Gallery` — untouched.

## 5. Dependencies, risks, open technical questions

- **R-1 — ✅ RESOLVED at Phase 2: 0066 and 0068 have both shipped and sit in `done/`.** The Phase 2
  review's symbol-disposition table (end of this file) re-verified every symbol this story binds to
  against HEAD 8857ffa: `UiLocale` (owned by **0068**, not 0066), `LocaleSetting::defaultUiLocale(): UiLocale`,
  `SetUserUiLocale::__invoke(UiLocale $locale, ?User $user = null): User`, the `SetUiLocale` fallback,
  `UserValidationRules::uiLocaleRules()` and the nullable `users.ui_locale` column all match. The
  original Phase 1 text is kept below as history. *Original:* **BLOCKING DEPENDENCY: story 0066 is
  specified but NOT implemented, and it now depends on 0068 in turn.** Re-verified during this revision: `app/Enums/UiLocale.php`, `app/Http/Middleware/` (the
  directory itself), `app/Actions/Users/SetUserUiLocale.php`, `app/Models/LocaleSetting.php` and any
  `ui_locale` reference in `app/Models/User.php` **do not exist**, and 0066 sits at
  `ai-spec/tasks/0066-…md` — the **new** stage, not `done/`. The real build order is **0068's
  `LocaleSetting` → 0066 → 0067**, which 0066's own **R-2a** records as a deliberate, documented
  inversion of [workflow.md](../../../docs/workflow/task-files-links-and-ordering.md#task-ordering-rule)'s numbering rule. **Phase 2
  must confirm both predecessors have closed before this story is picked up.**
- **R-2 — This story is written against a contract that has not survived its own implementation.**
  Because 0066 is unshipped, its Phase 2/3 could still move what this story binds to. Findings here are
  written as **properties that must hold** rather than as patches, for exactly that reason. **Before
  Phase 3, re-verify every 0066/0068 symbol named here against `HEAD` and record each disposition.**
  ⚠️ This is no longer hypothetical — see **R-8**.
- **R-3 — `$this->redirect()`'s navigation semantics: verified in vendor, still to be confirmed by
  the browser test.** `redirect($url, $navigate = false)` is a hard redirect by default (**D-5**,
  Phase 2 C-6).
- **R-4 — Flux components.** `frontend-expert` proposed `flux:menu.radio.group` / `flux:menu.radio`
  for the chrome and a `flux:radio.group`-family control for the settings page (matching
  `Appearance`'s segmented idiom). Phase 2 (C-6) confirmed the `flux/menu/radio` and `flux/radio`
  stubs exist in the installed `vendor/livewire/flux/stubs/resources/views/flux/`; Phase 3 confirms
  the final markup by the browser tests. Two known Flux/Blaze traps are recorded as **not
  applying**: the conditionally-bound `tooltip` prop and the `disabled:cursor-*` /
  `pointer-events-none` interaction both require a disabled branch, and neither control has one.
  `@js()` **is** safe in a `flux:` tag's attribute — the
  [corrected errors-log entry](../../../docs/errors-log/archive-2026-08-23-to-2026-08-26.md#two-directive-calls-in-one-blade-component-tags-attribute-string-silently-fail-to-compile--2026-08-26)
  establishes by execution that only an anonymous `<x-…>` tag fails to compile it.
- **R-5 — RE-MEASURED in correction pass 2 (RR-1): the real remaining gap is the page `<title>`, not
  the chrome labels.** The earlier list (`__('Settings')` / `__('Log out')`, the navlist `aria-label`,
  the navlist labels) was **wrong**: `lang/es.json` (commit 152e9a9) translates all of them. Method,
  run at HEAD 8857ffa: every `__('…')` / `__("…")` call in `resources/views/**` was extracted, domain
  keys (`file.key`) were skipped, and each remaining bare literal was looked up in `lang/es.json`.
  **Result: zero untranslated bare `__()` literals.** The only "misses" were hyphenated domain keys
  (e.g. `blog-posts.index.title`, which resolve through their own lang files) and one false positive
  from an escaped apostrophe (`Don\'t have an account?`, which exists in es.json). A search of the
  chrome files (`layouts/app.blade.php`, `layouts/app/sidebar.blade.php`,
  `components/desktop-user-menu.blade.php`, `components/settings/layout.blade.php`) found no visible
  text outside `__()`. **What does stay English in both locales:** the 24 `#[Title('…')]` attribute
  literals on Livewire components (e.g. `Profile settings`, `Dashboard`, `Orders`), which
  `resources/views/partials/head.blade.php` prints into `<title>` without `__()`. This is the browser
  tab title, and the topbar fallback when a screen declares no heading. Not measured: strings built
  in PHP outside views (notifications, validation messages, flash messages); those need a separate
  check. **Ownership:** Phase 2 found no story in `ai-spec/tasks/` or the Epic 5 PRD section that owns
  the `<title>` gap. A **small** follow-up story is warranted (for example, run the title through
  `__()` in `head.blade.php`, or move titles onto domain keys), but whether to open it is a product
  decision for the human. It is **not created by this story** and does not block 0067. The PRD's
  Layer 1 "menus, labels, and buttons" clause is still not checked off here, because the `<title>`
  gap remains and no full-interface audit was done.
- **R-6 — `resources/views/layouts/app/header.blade.php` is dead code, and it already misled one
  amigo.** Verified unreferenced. It holds stale copies of the chrome — a hardcoded mobile drawer
  ignoring the `config/modules.php` registry, and a bare `__('Dashboard')` — and `frontend-qa` traced
  it in good faith and drew a wrong conclusion about the live layout from it (**D-1**). Recommend
  deleting it in a **separate** cleanup story; leaving it unmentioned invites the same mistake again.
- **R-7 — ✅ RESOLVED at Phase 2 (C-6): `vendor/` is installed.** The Phase 1 amigos worked without it;
  the vendor-dependent claims have since been checked (hard-redirect default, Flux radio stubs — see
  **D-5**, **R-3**, **R-4**). Behaviour is still confirmed by the browser tests rather than by reading
  vendor code alone.
- **R-8 — NEW: 0066's contract changed underneath this story *during* Phase 1, and one decision here
  was already stale.** 0066 was substantially revised after this file's first draft: its fallback moved
  from `config('app.locale')` to `LocaleSetting::defaultUiLocale()` (story 0068's singleton), and it
  now implements `HasLocalePreference` so notification emails follow the same preference. **D-4** was
  corrected in place as a result. This is **R-2 materialising with a fuse measured in hours rather than
  stories**, and it is recorded rather than silently fixed because it is evidence for the rule, not
  just an edit: the correction was cheap **only** because D-4 was written as a property ("mirror the
  middleware's expression") rather than as a literal patch. Phase 3 must re-verify regardless.
- **R-9 — NEW, cross-story and NOT this story's to fix: 0068 is now stale about 0066.** Story 0068's
  **D26** and its open **Q5** both state that 0066 *"deliberately does not implement
  `HasLocalePreference`"* and that `default_notification_locale` therefore has **no consumer**. 0066's
  current version records the opposite — its **R-2** is resolved and **D-14** implements it, making
  0066 that setting's first consumer. So 0068 carries a stale claim and an open question that has
  already been answered elsewhere. Flagged for whoever owns 0068; **this story deliberately does not
  edit either file**, per its instructions.
- **Dependency — story 0068 (Store Languages + locale settings) is consumed transitively.** This story
  reads `LocaleSetting::defaultUiLocale()` through the shared trait (**D-4**) and imports
  `App\Enums\UiLocale`. It must **not** touch `locale_settings`, `store_languages`, or any Layer 2
  concept. Layer 2's store content languages remain entirely separate.

### Open questions for the human — none blocking

**Q1 — ✅ RESOLVED by the human.** The interface language must be settable from **both** the dashboard
chrome and a new Settings tab. Both surfaces are now specified; the mechanism that keeps them from
drifting is **D-17**, and the collision their co-rendering creates is **D-20**. The story's previous
recommendation (chrome only) is superseded and recorded rather than deleted, since the reasoning behind
it — that a second surface costs a component, a route, a nav entry and duplicate tests — was accurate
about the cost and simply not decisive against the human's preference. No longer open.

**Q2 — ✅ RESOLVED by the human after Phase 2 (C-3): option (a)** — key all four settings navlist
labels through `topbar.settings.*` and give the Language screen `topbar.settings.language` /
`language_subtitle` keys (**D-21**).

Nothing else in this file requires a human decision for 0067 itself. **R-5** (re-measured: the
untranslated `<title>`) is the human's call on whether a small follow-up story is opened (not created
here), **R-9** is a notification to another story's owner, and
**R-3**/**R-4** are confirmed by the Phase 3 browser tests.

## 6. Technical tasks for later backlog creation

1. ~~Confirm 0068 and 0066 have closed~~ — done at Phase 2 (**R-1**); re-check only if HEAD moves
   past 8857ffa before Phase 3 starts.
2. Add `label()` to `App\Enums\UiLocale`, returning `'English'` / `'Español'`, and rewrite its
   "Deliberately no `label()`" docblock paragraph.
3. Create `app/Concerns/InteractsWithUiLocale.php` with `currentLocale()` and `applyUiLocale()`
   (**D-17**) — **before** either component, so neither is ever written with inline logic.
4. Modify the existing `lang/en/localization.php` and `lang/es/localization.php` (add the `switcher.*`
   group, update the 0069 reservation comment), key-for-key identical; and add
   `settings.language` / `settings.language_subtitle` to both `lang/{en,es}/topbar.php`.
5. Create `App\Livewire\Settings\LanguageSwitcher` consuming the trait, with validation before
   `UiLocale::from()` (**D-19**) and the chrome redirect target (**D-18**).
6. Create `resources/views/livewire/settings/language-switcher.blade.php` — **nested**; confirm the
   resolved path by running the component and check for a stray scaffold stub at the flat path.
7. Confirm the Flux menu component against the installed stubs, then add the control to
   `resources/views/components/desktop-user-menu.blade.php`.
8. Add the same control, plus `data-test="mobile-menu-button"` on the `<flux:profile>` trigger, to
   the mobile dropdown inside `data-test="topbar-mobile-profile"` in
   `resources/views/layouts/app/sidebar.blade.php`.
9. Create `App\Livewire\Settings\Language` + `resources/views/livewire/settings/language.blade.php`,
   consuming the same trait with the settings redirect target and the `settings-language-*` hooks
   (**D-20**).
10. Register `language.edit` in `routes/settings.php`'s `auth` + `verified` group (**D-16**) and add
    the fourth navlist item to `resources/views/components/settings/layout.blade.php`, switching the
    three existing labels to `topbar.settings.*` keys in the same edit (**D-21**).
11. Write `tests/Feature/Localization/LanguageSwitcherTest.php`, `tests/Feature/Settings/LanguageTest.php`,
    `tests/Browser/Localization/AdminUiLanguageSwitcherTest.php` and
    `tests/Browser/Settings/LanguageTest.php`, including all three prove-it-can-fail steps and their
    recorded red results.
12. Run all three quality gates unscoped and record each result, including any not run.
13. Docs pass per the Definition of Done — adding `InteractsWithUiLocale` to the existing
    behavioural-traits listings (no widening needed, Phase 2 C-4) and a `LanguageSwitcher` row beside
    `Notifications\Bell` in `docs/api/routes.md` (C-5).
14. Out of this story: the human decides whether to open a small follow-up story for the untranslated
    `#[Title]` → `<title>` gap (**R-5**, re-measured in correction pass 2).

## Provenance

Phase 1 Three Amigos debate, facilitated by `product-owner`, in **two rounds** — the first covering the
chrome switcher, the second the Settings tab added by a human decision on Q1. `frontend-expert` and
`frontend-qa` were dispatched as real subagents in both rounds and all four dispatches returned.

**The amigos contradicted each other four times, and every resolution is recorded with its evidence
rather than by preferring an agent.**

| # | Split | Resolved | How |
| --- | --- | --- | --- |
| **D-1** | Is the account menu viewport-neutral? | Against `frontend-qa` | It traced the **dead** `layouts/app/header.blade.php`; verified by the facilitator that `app.blade.php` binds only `x-layouts::app.sidebar` and that the live desktop menu is `hidden lg:block`. Its *warning* was adopted anyway — the gap it predicted is real. |
| **D-16** | Which route group? | Against `frontend-qa` | `frontend-expert`'s reasoning is more specific: `profile.edit`'s bare `auth` exists for the unverified-user deadlock, which a language preference has no part in. Both noted it is functionally inert either way. |
| **D-17** | Embed one component, or share a trait? | Against `frontend-qa` | `frontend-expert` showed the chrome control already renders **twice** per page, so embedding would put **three** copies on one screen — a fact about the shipped DOM, not a preference. |
| **D-22** | Which browser-test folder? | Against `frontend-qa` | Its "technique differs" reasoning fits the chrome switcher, but the Settings tab is a screen, and `playwright-setup.md` names `tests/Browser/Settings/` explicitly as the mirrored convention. |

**`frontend-qa` also produced the round's sharpest single finding**, which *inverts* advice this file
previously gave: the `{en, es}` value-set assertion is **blind to duplication**, so with the chrome
switcher co-rendering on the settings page it cannot detect the very collision the second surface
creates (**D-20**). **One claim by `frontend-expert` was corrected by the facilitator**: its proposed
`validateOnly('locale', …)` operates on a declared component property, which **D-3** deliberately
removes — the corrected shape, and the two consequences that follow from it, are **D-19**.

**Independently verified by the facilitator** rather than taken from either amigo: the dead
`header.blade.php` (`grep -rn "app.header"` → no hits); `config/livewire.php`'s
`'component_layout' => 'layouts::app'` and the absence of any `#[Layout]` override, which is what makes
the co-render in **D-20** a fact rather than a guess; the `tests/Feature/Settings/` per-screen naming;
`settings/layout.blade.php`'s `wire:navigate` on every tab; and 0068's accessor signatures
(`defaultUiLocale(): UiLocale`, `defaultNotificationLocale(): UiLocale` — both returning an enum, hence
`->value`), which is what **D-4**'s correction rests on.

**Q1 was escalated to the human and answered: both surfaces.** **R-8** and **R-9** were found while
re-reading 0066 and 0068 during this revision, not by any change→doc mapping.

_Phase 1 only. No INVEST check, no TDD, no security audit, no code review, no docs pass — those are
Phases 2–7 and are orchestrated separately._

---

## Phase 2 review — INVEST + documentation check (code-reviewer, 2026-10-02)

**Verdict: ❌ REJECTED — return to `product-owner` for a targeted correction pass.** INVEST holds
(Independent now that 0066/0068 are `done/`; Negotiable; Valuable; Estimable; Small enough given the
human's Q1 decision; Testable), but the story fails the documentation-consistency half: several
repo-state claims are stale against `HEAD` (8857ffa) and **D-21 contradicts
[localization.md Rule 2](../../../docs/conventions/localization.md#rule-2--admin-dashboard-code-must-not-assume-a-fixed-rendering-locale)**.
All corrections are mechanical except C-3, which needs a decision.

### R-1 / R-2 / R-8 — 0066/0068 symbol dispositions at HEAD

| Symbol | At HEAD | Disposition |
| --- | --- | --- |
| `App\Enums\UiLocale` | `English='en'`, `Spanish='es'`; no `label()`, no `default()` | Matches. **Drift:** owned by **0068** (moved from 0066), not "0066's file" — fix the wording in *Shared enum and translations*. Its docblock "Deliberately no `label()`" must be updated when `label()` lands. |
| `LocaleSetting::defaultUiLocale()` | `public static function defaultUiLocale(): UiLocale` (3-tier `tryFrom` chain); `SINGLETON_ID = 1` | Matches (`->value` required, as D-4 says). |
| `SetUserUiLocale` | `__invoke(UiLocale $locale, ?User $user = null): User`, self-only derived from `Auth::user()`, writes via `forceFill` | Matches; `$setUserUiLocale(UiLocale::from($locale))` is a valid call. |
| `SetUiLocale` fallback | `UiLocale::tryFrom((string) $stored)->value ?? LocaleSetting::defaultUiLocale()->value` | Semantically matches the trait's `?->value ??` (`??` applies isset semantics to the whole chain). Optional: copy the literal form. Note `User::preferredLocale()` is an existing second copy (notification fallback). Registered in `bootstrap/app.php` web group — matches. |
| `UserValidationRules::uiLocaleRules()` | `protected`, `['required', 'string', Rule::enum(UiLocale::class)]` | Matches (refuses `''`, `'EN'`, `'en-US'`, `'fr'`). |
| `users.ui_locale` | `string(5)->nullable()`, no backfill | Matches. |
| R-1 / R-2 / R-8 | 0066 and 0068 both in `done/` | **Resolved.** Update the *Type* line ("specified, not yet shipped") and R-1. |

### Required story corrections

- **C-1 — Sidebar structure (D-1, D-14, *Files*).** There is no `<flux:header class="lg:hidden">` block at
  lines 26–78 any more: story 0057a replaced it with one persistent topbar (`data-test="topbar"`). Desktop
  menu is `sidebar.blade.php:23`; the mobile dropdown is lines 54–102 inside
  `<div class="lg:hidden" data-test="topbar-mobile-profile">`. D-1's conclusion (two render sites) still
  holds. The mobile `<flux:profile>` (line 56) still has no `data-test`, so D-14 stays valid — or scope via
  the existing `topbar-mobile-profile` wrapper.
- **C-2 — `lang/{en,es}/localization.php` already exist** (empty, shipped by 0068, header comment reserving
  them for **0069**'s `attributes` + `settings.*`). Change "new pair" to **modify**, add the `switcher.*`
  group alongside, update the reservation comment, and record the same-file conflict risk with 0069.
- **C-3 — D-21 is stale and conflicts with localization.md Rule 2 (decision needed).** Settings headings and
  subheadings are **no longer** keyless: every settings view passes `<x-slot:heading>{{ __('topbar.settings.<tab>') }}`
  / `<x-slot:subheading>` from `lang/{en,es}/topbar.php` (`settings.profile|security|appearance` +
  `*_subtitle`, translated in ES). The Language view must follow that pattern with new
  `topbar.settings.language` / `language_subtitle` keys in both `topbar.php` files (add them to *Files*),
  not `<x-settings.layout>` heading props. Only the navlist labels are still bare. Rule 2 forbids a new bare
  `__('Language')`. Options: **(a) _(recommended)_** key the new navlist item with `topbar.settings.language`
  and switch its three siblings to the existing `topbar.settings.*` keys — same file, three lines, no new
  copy, no mixed-language navlist; (b) key only the new item (compliant, but ES navlist reads
  "Profile / Security / Appearance / Idioma"); (c) keep it bare as a documented exception to Rule 2. Update
  the Gherkin callout, *Expected outcome*, DoD and R-5 to match. `#[Title('Language settings')]` as a bare
  literal is acceptable (an attribute cannot call `__()`; siblings do the same).
- **C-4 — D-17 / DoD docs item: `app/Concerns/` is no longer validation-only.** It already holds
  `HasTranslations`, `Translatable`, `ChecksAbilitiesSafely`, `ResolvesFlagReasonLabel`,
  `ResolvesSalesRegionFromAddress`, and base-standards was already widened. Replace "must be widened" with
  "add `InteractsWithUiLocale` to [stack-and-model-conventions.md#behavioural-traits-in-appconcerns](../../../docs/conventions/base-standards/stack-and-model-conventions.md#behavioural-traits-in-appconcerns)
  and `directory-structure/app-layers.md`".
- **C-5 — DoD routes.md wording.** The chrome switcher is not "the second routeless surface after
  `Media\Gallery`, and the first ungated". `WysiwygEditor` is also routeless, and `Notifications\Bell` is
  already a routeless, ungated chrome component with its own row at `docs/api/routes.md:153`. The switcher
  should get a row in that same table.
- **C-6 — R-7 / R-3 / D-5 / R-4 are now checkable: `vendor/` is installed.**
  `HandlesRedirects::redirect($url, $navigate = false)` does a **hard** redirect by default (render
  skipped unless `livewire.render_on_redirect`). `flux/menu/radio` and `flux/radio` stubs exist. Keep
  "confirm by browser test", but drop the "vendor absent" hedges.
- **C-7 — D-13 minor.** `resources/views/dashboard.blade.php` no longer exists (the dashboard is
  `livewire/dashboard/overview`, title `topbar.dashboard.title` = `Dashboard`/`Inicio`). The recommended
  `sales_regions` pair and the `sidebar-link-sales_regions` hook are verified, so the recommendation stands.
- **C-8 — Test note.** `data-test="logout-button"` appears in **both** account menus (desktop and mobile
  co-render), so the persistence journey's logout click must be scoped (e.g. inside the desktop dropdown)
  or it hits the same duplicate-hook trap as D-20
  ([waiting-rules.md](../../../docs/testing/frontend/playwright-setup/waiting-rules.md#a-page-embedding-the-same-component-twice-duplicates-every-data-test-hook)).

**Verified unchanged:** `routes/settings.php` groups (`auth` = profile; `auth`+`verified` = appearance,
security+`password.confirm`); the settings layout navlist (3 items, all `wire:navigate`);
`config/livewire.php` `component_layout => 'layouts::app'`; `layouts/app/header.blade.php` unreferenced;
`tests/Feature/Settings/` per-screen naming; `tests/Browser/Settings/` and `tests/Browser/Localization/`
absent; none of the files to create exists (`localization.php` excepted, C-2); every doc anchor cited by
the story resolves.

### R-5 — ownership of the hardcoded-chrome translation sweep

**No owner.** Nothing in `ai-spec/tasks/` (pending or `done/`) or `docs/PRD/sections/epic-5-internationalization.md`
owns extracting `__('Settings')` / `__('Log out')`, the settings navlist labels, or the other bare-literal
chrome. 0069 only touches its own screen's copy. **This is a decomposition gap in Epic 5**: escalate to
`product-owner` to open a follow-up story. It does not block this story. The gap shrinks if C-3(a) is
adopted.

### Correction pass applied (product-owner, 2026-10-02)

All of C-1..C-8 were applied in place above; the review text is kept unchanged as the record. Summary:
C-1 *Files*/D-1/D-14/task 8 now describe the 0057a topbar and the `topbar-mobile-profile` mobile
dropdown (two render sites unchanged). C-2 `localization.php` is **modified** (new `switcher.*` group,
reservation comment updated, same-file conflict risk with 0069 recorded). C-3 resolved by the human as
**option (a)**: D-21 rewritten, the four navlist lines keyed via `topbar.settings.*`, new
`topbar.settings.language` / `language_subtitle` keys, Scope fences / R-5 / Gherkin callout /
*Expected outcome* / acceptance criteria / DoD updated, plus a new Spanish-navigation Gherkin scenario
and a Feature test asserting the navlist in both locales. C-4 DoD and D-17 now add
`InteractsWithUiLocale` to the existing behavioural-traits listings. C-5 DoD names a row beside
`Notifications\Bell` in `docs/api/routes.md`. C-6 vendor hedges dropped (D-5, R-3, R-4, R-7) while
keeping browser-test confirmation. C-7 `dashboard.blade.php` claim marked historical. C-8 logout
click must be scoped to one menu. Type line and R-1 updated (0066/0068 shipped; `UiLocale` owned by
0068, docblock to be rewritten). R-5 records that a follow-up story is needed (not created here).
**Ready for Phase 2 re-review.**

## Phase 2 re-review (code-reviewer, 2026-10-02)

**Verdict: ❌ REJECTED — one remaining factual error, mechanical to fix; no new human decision needed.**

**C-1..C-8 verified applied at HEAD 8857ffa:** sidebar line refs (`:23` desktop menu, `:54` `topbar-mobile-profile`, `:56` `<flux:profile>` without `data-test`) correct; `localization.php` now *modified* with the 0069 conflict risk recorded; D-21 / Files / Scope fences / AC / DoD / task 10 consistently describe four keyed navlist lines and two new `topbar.settings.*` keys (`profile|security|appearance` exist in both `topbar.php`); C-4/C-5/C-6/C-7/C-8 applied; Gherkin has 15 scenarios as §2 states, each with a named actor and one `When`. The new Spanish-navlist scenario and its Feature test are testable as written: `$this->actingAs($esUser)->get(route('profile.edit'))` runs the `web` group, so `SetUiLocale` runs, and `profile.edit` is in the bare `auth` group. Cited anchors (`behavioural-traits-in-appconcerns`, the waiting-rules duplicate-hook heading) resolve. No leftover "new pair", "first ungated", "vendor absent" or live `flux:header lg:hidden` claim. INVEST holds.

**RR-1 (blocking) — the "bare literals stay English in both locales" premise is false at HEAD.** `lang/es.json` (commit 152e9a9, 2026-09-30) translates `Settings` → `Ajustes`, `Log out` → `Cerrar sesión`, `Profile` → `Perfil`, `Security` → `Seguridad`, `Appearance` → `Apariencia`, `Dashboard` → `Panel`. So the account menu's `__('Settings')` / `__('Log out')`, the navlist's `aria-label`, and the three current navlist labels **already render in Spanish**. Correct these claims: the Gherkin callout (lines ~103–110), D-11 ("render identical English in both locales"), D-21 (option (b)'s "Profile / Security / Appearance / Idioma" rationale, and "except the navlist's `aria-label`"), *Expected outcome* ("stays English in both locales"), R-5 (its listed examples are translated — re-measure the real remaining gap, which may be small, before escalating a follow-up story), and D-13 (`Dashboard` is not English-only either). Decision (a) itself still stands — it is the Rule 2 ✅ shape (domain keys, not JSON-literal keys) — only its "no mixed-language navlist" justification is wrong. Note for the Feature test: with es.json in place, the Spanish case is red before implementation **only** on `Idioma`; that is acceptable, but record it so nobody mistakes the sibling labels' green for proof of the key switch (the raw-key typo risk is still caught).

**Non-blocking:** scoping the navlist assertions to a `data-test` on the `<flux:navlist>` would make them independent of the topbar title and the co-rendered chrome switcher's `Language` / `Idioma` heading; Phase 3 may add it. `docs/conventions/localization.md` Rule 2's `__('Cancel')` example is also stale against es.json — a docs-keeper note, not this story's edit.

### Correction pass 2 (product-owner, 2026-10-02)

RR-1 applied in place; the re-review text above is kept as the record. The false premise that
`__('Settings')` / `__('Log out')` / the navlist labels / `aria-label` / `Dashboard` stay English is
corrected in the Gherkin callout, D-11, D-13 and the §3 historical bullet, D-21, *Expected outcome* and
the *Files* note. D-21 option (a) is unchanged but is now justified by localization.md Rule 2's domain
keys, not by a mixed-language navlist. R-5 was **re-measured** at HEAD 8857ffa. Every bare
`__()` literal in `resources/views/**` already has an es.json entry, so there are zero untranslated
literals. The real remaining gap is the 24 `#[Title]` literals that `partials/head.blade.php` prints
into `<title>` without `__()`. Opening a small follow-up story for it is left to the human. The Feature
test now records that the Spanish case goes red before implementation **only** on `Idioma`. It also
requires every navlist assertion to be scoped to a new `data-test="settings-navlist"` hook on
`<flux:navlist>`, which is added to *Files*, Scope fences and the acceptance criteria.
**Ready for Phase 2 re-review.**

## Phase 2 re-review 2 (code-reviewer, 2026-10-02)

**Verdict: ✅ APPROVED — ready for Phase 3.**

RR-1 is resolved. I checked the claims myself at HEAD 8857ffa:
- `app/Livewire/**` has exactly 24 `#[Title('…')]` attributes.
- `resources/views/partials/head.blade.php` prints `$title` raw, without `__()`. So `<title>` stays English even where es.json has a matching entry (`Dashboard`, `Products`).
- Every capitalised bare `__('…')` literal in `resources/views/**` has a `lang/es.json` entry. That confirms R-5's "zero untranslated bare literals".

No "stays English in both locales" claim about the chrome or navlist labels remains. The phrase now appears only for the `<title>` (Gherkin callout, *Expected outcome*, R-5) and inside the marked historical corrections (D-11, D-21, D-13).

The `data-test="settings-navlist"` hook appears consistently in *Files*, Scope fences, the acceptance criteria, D-21 and the Feature test. That test's "red only on `Idioma`" note is accurate. INVEST still holds.

Non-blocking: R-5's side remark that the `#[Title]` is also "the topbar fallback when a screen declares no heading" was not verified here. It does not affect this story.

## Phase 4 security audit (appsec-auditor, 2026-10-02)

**Verdict: ✅ APPROVED — no Critical/High/Medium findings.** One Low defense-in-depth item and two informational notes; none blocks Phase 5.

Verified:
- **Forged locale:** both `setLocale()` methods run `Validator::make(...)->validate()` with `uiLocaleRules()` (`required|string|Rule::enum(UiLocale)`) before `UiLocale::from()`. A forged string is refused with a validation error and no write. No `ValueError`/500 occurs.
- **Self-only write:** `SetUserUiLocale` is called with the locale only. The target comes from `Auth::user()`, and the client cannot send a user id. Only `ui_locale` is written through `forceFill`, and `User`'s `#[Fillable]` is unchanged.
- **Livewire surface:** neither component has public properties, so there is nothing to mass-assign or tamper with. `applyUiLocale()` is `protected`, and `#[Computed] currentLocale` cannot be called directly (`CannotCallComputedDirectlyException`). It uses `tryFrom` to fall back safely.
- **Middleware/authorization:** the `language.edit` route sits in the `auth` + `verified` group. Livewire's persistent middleware re-applies `Authenticate` on `/livewire/update`, so a guest cannot call `setLocale`. No gate or role is touched and no privilege changes. CSRF is enforced by Livewire's update endpoint and the snapshot checksum.
- **Output encoding:** endonym labels and `__()` strings are escaped with `{{ }}`/Flux props. The `wire:click` arguments are fixed enum backing values with no user data.
- **Redirect:** the response is a Livewire JSON redirect effect, not a `Location` header, so header injection is not possible. `UrlGenerator::to()` prefixes non-http(s) schemes with the app root, so a `javascript:` target cannot occur.

Findings:
- **L-1 (Low, CWE-601 defense-in-depth):** `app/Livewire/Settings/LanguageSwitcher.php:29` uses `url()->previous()`, which trusts the raw `Referer` header. Only the requester can forge their own Referer: a cross-site attacker cannot because of the CSRF token and snapshot checksum. So this is a self-only open redirect today. Because `previous()` never returns a falsy value, the `?: route('dashboard')` fallback is also dead code. Recommendation: keep the redirect on this host, for example `$this->applyUiLocale($locale, $setUserUiLocale, url(url()->previousPath()))`, or compare `parse_url(url()->previous(), PHP_URL_HOST)` with `request()->getHost()` and fall back to `route('dashboard')`.
- **I-1 (Info):** a forged non-string argument such as `setLocale([])` hits a PHP `TypeError` on the typed `string $locale` parameter before validation runs. The result is a generic 500 with no write and no data exposure when `APP_DEBUG=false`. This is acceptable, and changing to `mixed` plus validation is optional.
- **I-2 (Info):** the actions have no throttle. Each call is one authenticated write to the caller's own row, so the abuse value is negligible.

## Phase 5 code review (code-reviewer, 2026-10-02)

**Verdict: ❌ REJECTED. Larastan fails, and the full suite has two red tests caused by this story.** The fixes are small and mechanical. Everything else checks out: the code meets the acceptance criteria and the conventions, and the scope fences hold.

**Quality gates (all unscoped):**
- `vendor/bin/pint --format agent`: **passed**, no files changed.
- Larastan level 7 (`vendor/bin/phpstan analyse`): **failed, 2 errors**. Both are the same line, reported once for each consuming class: `app/Concerns/InteractsWithUiLocale.php:27`, `nullsafe.neverNull`. The message is "Using nullsafe property access `?->value` on left side of `??` is unnecessary".
- Full suite: run in 14 directory chunks that cover `tests/Unit`, all 33 `tests/Feature/*` folders plus the two top-level files, and every `tests/Browser` folder plus the five top-level files. **5,422 tests: 5,410 passed, 2 failed in Feature/Unit, 2 failed in Browser, 8 skipped.** Feature/Unit was 5,179 tests and Browser was 243.
  - Feature/Unit, 2 failed, both caused by this story:
    - `tests/Unit/Enums/UiLocaleTest.php:42` "the enum declares no label method and no default method". 0068's test pins that `label()` is absent, and this story adds `label()`.
    - `tests/Feature/Layout/TopbarTest.php:166` "every authenticated app screen is covered…". `language.edit` is not in `topbarScreens()`.
  - Browser, 2 failed, not caused by this story: `UsersIndexTest`'s per-row edit and delete tests timed out while another worktree's suite was loading the machine. Rerun alone, the file passed 14/14, so these are load flakes.

**Required fixes:**
1. **Larastan.** In `InteractsWithUiLocale::currentLocale()`, change `UiLocale::tryFrom(...)?->value ??` to `->value ??`. This is the literal form that `SetUiLocale` and `User::preferredLocale()` already use, and `??` gives the whole chain isset semantics, so the behaviour does not change. Do not suppress the error.
2. **`tests/Unit/Enums/UiLocaleTest.php`.** Rewrite the "no label method" test. It should assert that `label()` returns `English` / `Español`, and that `default()` still does not exist. The story's *Files* section omitted this test, and it should list it.
3. **`tests/Feature/Layout/TopbarTest.php`.** Add `'language settings' => ['language.edit', 'topbar.settings.language']` to `topbarScreens()`. That also gives the Language screen the same topbar title and subtitle coverage as its siblings.
4. **Redirect with no previous URL (the known deviation): fix the code and the story text.** Laravel's `url()->previous()` never returns a falsy value. When there is no Referer and no session previous URL, it returns `url('/')`, which is the public `welcome` page (`routes/web.php`). That page is outside the admin panel, so it is the wrong place for an administrator to land. Laravel already has the right hook for this: change `sameHostPreviousUrl()` to read `$previous = url()->previous(route('dashboard'));` and keep the same-host check. Then change the feature test "stays on this host when there is no previous URL" to `assertRedirect(route('dashboard'))`. In the story, update the *Files* snippet and D-18: they describe `url()->previous() ?: route('dashboard')`, which is dead code (see Phase 4 L-1). They should describe "a same-host `url()->previous(route('dashboard'))`, falling back to `route('dashboard')`".
5. **There is no Phase 3 record in this file.** The Definition of Done says all three prove-it-can-fail steps must be performed *and their red result recorded*. This file has no Phase 3 section, so none of them is recorded. Append a Phase 3 record that covers the three red results and the green story-test count.

**Verified (passes):**
- All four settings navlist lines use `topbar.settings.*` keys, and `<flux:navlist>` has `data-test="settings-navlist"`.
- The new keys appear key-for-key in both `topbar.php` files and both `localization.php` files, and no bare `__()` literal was added (Rule 2). The endonyms on `UiLocale::label()` are the documented D-9 exception.
- The same trait and the same action are used on both surfaces. Validation runs before `from()`.
- The hooks are distinct (`language-*` / `settings-language-*`), and `mobile-menu-button` was added.
- The views are nested, with no flat stub left behind. `#[Title('Language settings')]` is present.
- The route sits in `auth` + `verified` with no `password.confirm`.
- None of the following changed: `config/`, `database/`, the middleware, the models, the actions, `sidebar-nav`, `navigation.php`, `header.blade.php`. No permission was added.
- The other changes outside the code are link-integrity repoints for the move to `in-progress/`.

**Not blocking:**
- `LanguageSwitcher::setLocale()` has an inline `//` comment where code-style prefers PHPDoc.
- The same `Validator::make` block appears in both components. That matches the story's "thin `setLocale()` per component" decision, so it is acceptable.

**Phase 6 (docs-keeper), still pending:** the `routes.md` route row, the `LanguageSwitcher` row next to `Notifications\Bell`, the `InteractsWithUiLocale` entries in the behavioural-traits listings, and the three `naming.md` notes.

## Phase 3 record (TDD — recorded after Phase 5 item 5, 2026-10-02)

Recorded by `product-owner` from the results the coordinator reported for the Phase 3 run. Phase 5
asked for this section because the Definition of Done requires the prove-it-can-fail red results to
be recorded.

**Red state.** The 33 new tests in the four story files were written first and all went red for the
right reasons: the missing route, components, view hooks and translation keys, not setup errors.

**Green state.** All four story files are green:
`tests/Feature/Localization/LanguageSwitcherTest.php`, `tests/Feature/Settings/LanguageTest.php`,
`tests/Browser/Localization/AdminUiLanguageSwitcherTest.php` and
`tests/Browser/Settings/LanguageTest.php`. They now hold **36 tests / 88 assertions**. That count
includes the 3 chrome-redirect tests added after Phase 4 L-1 (foreign-host previous URL → dashboard,
same-host previous URL kept, no previous URL → dashboard).

**One test-design issue found and fixed.** The sign-out/sign-in journey test asserted
`assertSee('Log in')` after logout. Logout redirects to `/`, the public welcome page ("Coming soon"),
which renders no `Log in` text, so that assertion could never pass and the second half of the journey
(sign back in, still Spanish) never ran. It was replaced with a polling assertion that the sidebar is
absent, which only holds once the sign-out redirect has landed.
- *Root cause:* the assertion expected visible text on the post-logout page without checking what
  that page renders.
- *Lesson:* after a redirecting action, assert something the destination page is known to render, or
  the absence of an element that only exists in the previous state.

**Prove-it-can-fail results (DoD).**
- **(a) Exactly two options:** a decoy third option was rendered on each surface. The chrome and
  settings "exactly two options" tests both went **red**: the value set was not `{en, es}` and the
  scoped count was not 2. Reverted.
- **(b) Round trip:** the action call inside `applyUiLocale()` was made a no-op. **6 tests went red**:
  Feature "a valid choice is persisted to the signed-in users own row" (×2, one per component), the
  chrome round trip, the sign-out/sign-in journey, the settings tab switch and the cross-surface
  reflection test. Restored.
- **(c) Hook collision (D-20):** with the settings surface given the chrome's unsuffixed hooks, the
  settings browser test went **red**, because its suffixed hooks were gone. A copy of the test using
  unsuffixed hooks **failed loudly** (a click timeout and a scoped count that was not 2). It never
  passed while driving the chrome control. Restored.
- **L-1 guard:** the foreign-Referer redirect test was proven **red** by reverting the host check,
  then restored.

## Phase 5 re-review (code-reviewer, 2026-10-02)

**Verdict: ✅ APPROVED — ready for Phase 6 (docs-keeper).**

**All five required fixes are applied, and I checked each one in the code:**
1. `InteractsWithUiLocale::currentLocale()` now uses `->value ??`. This is the same form `SetUiLocale` uses.
2. `UiLocaleTest` now asserts that `label()` returns `English` / `Español`, and that `default()` is still absent.
3. `TopbarTest::topbarScreens()` now includes `'language settings' => ['language.edit', 'topbar.settings.language']`.
4. `LanguageSwitcher::sameHostPreviousUrl()` now reads `url()->previous(route('dashboard'))` and keeps the same-host check. The no-previous-URL test now expects `route('dashboard')`. The *Files* snippet, D-18 and the acceptance criteria describe the same behaviour as the code.
5. The Phase 3 record is appended. It records all three prove-it-can-fail red results, the L-1 guard red, and the 36 green story tests. It also states the corrected logout fact: the welcome page renders no "Log in" text.

**Quality gates (all unscoped):**
- `vendor/bin/pint --format agent`: **passed**, and it changed no files.
- Larastan level 7 (`vendor/bin/phpstan analyse`): **passed, 0 errors**.
- Full suite: **5,426 tests — 5,418 passed, 0 failed, 8 skipped.**
  - It ran in 14 directory chunks. Together they cover `tests/Unit`, all 33 `tests/Feature/*` folders plus `DashboardTest.php` and `ExampleTest.php`, and every `tests/Browser/*` folder plus the five top-level Browser files.
  - Feature/Unit: 5,183 tests (5,178 passed, 5 skipped). These ran on the two databases this worktree owns.
  - Browser: 243 tests (240 passed, 3 skipped). These ran alone on one database.
  - The 0084 worktree was **not** running tests during the browser chunks. The only 0084 process alive was an idle, leftover `playwright run-server`. The `UsersIndexTest` load flakes from the first review did not recur.

## Closure note (Phase 7, 2026-10-02)

Closed and moved from `ai-spec/tasks/in-progress/` to `ai-spec/tasks/done/`. Every Acceptance criteria
and Definition of Done checkbox above is ticked on the evidence recorded in this file: the Phase 3
record (36 green story tests, the three prove-it-can-fail red results and the L-1 guard), the Phase 4
security audit (no Critical/High/Medium; Low L-1 fixed and re-verified in Phase 5), the Phase 5
re-review (Pint, Larastan level 7 with 0 errors, full suite 5,426 tests / 0 failed, all five required
fixes applied) and the Phase 6 docs pass (`docs/api/routes.md`, the behavioural-traits listings,
`app-layers.md`, and the naming notes in `docs/conventions/naming/translation-keys-and-booleans.md`).
The checkboxes inside "Tests to perform" are the planned test inventory, not acceptance gates; they
stay as written, since the test files themselves are the evidence. The PRD clause that the whole
interface is shown in the chosen language remains **not** satisfied by this story (D-11, R-5).
