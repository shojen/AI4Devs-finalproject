# [0057a] Notification bell relocation — persistent topbar with page title, subtitle and bell at the top right

## Description

Story [0057](../done/0057-notification-bell-ui.md) shipped the notifications bell at the **bottom of the desktop
sidebar** (and in the mobile header) because no persistent desktop topbar existed (its **D-1**). The project
owner has rejected that placement: the PRD's prototype
([`docs/PRD/images/01-inicio.png`](../../../docs/PRD/images/01-inicio.png), PRD
[§ Cross-cutting: global search & notifications](../../../docs/PRD/PRD.md#cross-cutting-global-search--notifications):
*"Both controls are present on every authenticated screen, matching the prototype topbar"*) shows the bell
**top right**, in a topbar that also carries the **page title and subtitle** on the left. This story builds
that topbar on every authenticated screen, moves the bell into it, and removes the bell from the sidebar.

> **⛔ Global search is still OUT OF SCOPE and still unowned.** No search field, no placeholder, no reserved
> space for one. Same exclusion as [0057](../done/0057-notification-bell-ui.md); see
> [0056's OQ-1](../done/0056-notification-viewing-backend.md#open-questions).

> **Reverses 0057 D-1, on the owner's explicit instruction.** D-1's premise ("no topbar exists, mount into
> existing chrome") is what this story removes; its reversal path ("relocating the bell is a one-line move of
> the mount point") is exactly how the bell component itself stays untouched apart from one dropdown-placement
> attribute.

## Type

frontend | includes database-expert: **no**

### Three Amigos participants

- `frontend-expert` — one topbar for all breakpoints (bell mounted **once**), title/subtitle mechanism via
  Livewire named slots, per-screen table, dropdown placement, dead `layouts/app/header.blade.php` finding.
- `frontend-qa` — Gherkin, structural-containment assertions, the hybrid route-dataset + guard Feature test
  for "every screen", regression blast radius on 0057's browser suite.
- **No `database-expert`**: no table, column, index, migration or query.

### Size

Estimated **L** (layout + 14 views + lang + two new test files). The INVEST review suggested splitting into
"topbar + bell relocation + dashboard" and "per-screen title/subtitle"; **kept as one story on purpose**: the
owner scoped title/subtitle into this change, and shipping the bell alone would leave the PR short of what was
asked. The internal order of work (layout first, then screens) is the natural commit split.

### Owner decisions (not reopened here)

1. Scope = topbar with **title + subtitle (left) and bell (right)**; no search.
2. The bell is **removed from the desktop sidebar**.
3. Branch cut from `finalproject-ARP`, own PR against it.

## Gherkin

```gherkin
Feature: Persistent topbar with page title, subtitle and notification bell

  Scenario Outline: The bell sits at the top right of the topbar
    Given an administrator using a <screen size> screen
    When they open the dashboard
    Then the notification bell is shown in the right-hand area of the topbar

    Examples:
      | screen size |
      | wide        |
      | phone-sized |

  Scenario: The left menu no longer offers the bell
    Given an administrator using a wide screen
    When they open the dashboard
    Then the left menu does not offer the notification bell

  Scenario Outline: The topbar shows each screen's title and subtitle
    Given an administrator with access to every module
    When they open the <screen> screen
    Then the topbar shows that screen's title and subtitle

    Examples:
      | screen           |
      | dashboard        |
      | users            |
      | customers        |
      | new product      |
      | edit product     |
      | profile settings |

  Scenario: A customer's detail screen titles the topbar with the customer's name
    Given an administrator viewing the customers list
    When they open a customer's detail screen
    Then the topbar shows that customer's name as its title

  Scenario: The topbar title follows the administrator to another screen
    Given an administrator on the dashboard
    When they go to the users screen
    Then the topbar shows the users title and subtitle instead of the dashboard's

  Scenario Outline: The bell appears once on each authenticated screen
    Given an administrator with access to every module
    When they open the <screen> screen
    Then the topbar shows the notification bell exactly once

    Examples:
      | screen                  |
      | dashboard               |
      | users                   |
      | roles                   |
      | products                |
      | shipping                |
      | payment methods         |
      | customers               |
      | security settings       |

  Scenario: The unread indicator shows from the topbar
    Given an administrator holding an unread notification
    When they open the dashboard
    Then the topbar bell shows an unread indicator

  Scenario: Opening the bell from the topbar lists notifications and clears the indicator
    Given an administrator holding an unread notification
    When they open the topbar bell
    Then their notifications are listed and the unread indicator is gone

  Scenario: The notification list is not obscured by the topbar
    Given an administrator holding notifications
    When they open the topbar bell
    Then each notification is visible and can be clicked
```

## Files to create/modify

### Modify

```
resources/views/layouts/app/sidebar.blade.php   -- replace the lg:hidden flux:header with ONE sticky topbar; drop the desktop-sidebar bell mount; add data-test="sidebar" on the flux:sidebar
resources/views/layouts/app.blade.php           -- forward the heading / subheading slots to the sidebar layout
resources/views/dashboard.blade.php             -- declare heading/subheading (plain Blade view)
resources/views/livewire/notifications/bell.blade.php -- dropdown position="bottom" align="end"
resources/views/livewire/{users,roles,sales-regions,products,product-categories,shipping,payment-methods,customers}.blade.php
resources/views/livewire/{customers/show,products/editor,products/attribute-types,shipping/zones}.blade.php
resources/views/livewire/settings/{profile,security,appearance}.blade.php
resources/views/partials/settings-heading.blade.php   -- remove the shared <h1>Settings</h1> and (with the sr-only headings) let the topbar carry it
resources/views/components/settings/layout.blade.php  -- section heading/subheading move to the topbar; the settings sub-navigation stays
lang/{en,es}/topbar.php  (new files)            -- new title/subtitle keys, key-for-key identical (one file instead of edits across a dozen domain files; screens that already owned a title key reuse it)
app/Livewire/Notifications/Bell.php             -- docblock says "twice"; now once
tests/Browser/Notifications/BellTest.php        -- drop the duplicate-hook/visible-instance workarounds
docs/api/routes.md                              -- layout-mounted component section: single mount in the topbar
```

### Create

```
tests/Feature/Layout/TopbarTest.php             -- route dataset + guard test + one-bell assertions
tests/Browser/Layout/TopbarTest.php             -- placement, title/subtitle, navigation, dropdown not obscured
```

### The topbar (ONE bar, every breakpoint)

`<flux:header sticky data-test="topbar" class="border-b … bg-white/85 backdrop-blur dark:bg-zinc-800/85 min-h-[65px]">`
containing, left to right: `flux:sidebar.toggle` (mobile only), the title block (`<h1 data-test="topbar-title">`
and `<div data-test="topbar-subtitle">`, both `min-w-0 truncate`), `flux:spacer`, `<livewire:notifications.bell />`
**once**, and the existing mobile-only profile dropdown wrapped in `lg:hidden` (the desktop user menu stays at the
bottom of the sidebar). Explicit dark-mode backgrounds are mandatory (`<html class="dark">` is hardcoded).
Prototype reference: `docs/arospe-handoff/project/css/common.css` (65px, bottom border, translucent blur).

### Wiring detail

`layouts/app.blade.php` forwards the two slots to `x-layouts::app.sidebar`. They arrive as `ComponentSlot`
objects (or `null`), so the topbar tests presence with `filled($heading)` / `isset($subheading) && $subheading->isNotEmpty()`,
never a bare `??` — the "customer detail has no subtitle" and dashboard cases depend on it. The topbar is
`flux:header` (`[grid-area:header]`, spans the main column beside the sidebar) and sits **outside** `<flux:main>`;
verify Flux passes `data-test` through on both `flux:header` and `flux:sidebar`, and that no second bell is
rendered in the mobile off-canvas copy of the sidebar.

### Title / subtitle mechanism

`#[Title]` keeps feeding `<title>` only. The topbar reads `$heading ?? $title` and `$subheading ?? null`; each
view declares them as **translated named slots** (`<x-slot:heading>{{ __('users.index.title') }}</x-slot:heading>`).
The topbar title is a real `<h1>` and **the page's only one**. Note `flux:heading` without `level` renders a `<div>`,
so today only the settings partial has an `<h1>`: on the other screens the inline `<flux:heading size="xl">` (and
its subheading) is simply removed, and on settings the partial's `<h1>` goes too. Action buttons that shared the
heading's row stay. **All copy resolves from real keys.** `lang/` holds only `en/` and `es/` PHP files (no JSON), so a bare
`__('Users')` / `__('Dashboard')` is an untranslated literal today: every title and subtitle below gets a real
key in both locales (the dashboard reuses `navigation.items.dashboard` if its text fits, else a new key), and tests
compare against `__(key)`, never a literal. Existing **dynamic count
summaries** (`users.index.summary`, …) stay inline under the toolbar, and the topbar subtitle is **static
copy** (D-3).

| Screen | Title | Subtitle |
| --- | --- | --- |
| Dashboard | `__('Dashboard')` existing | new |
| Users, Roles, Customers, Product categories, Attribute types | new `*.title` keys (today inline literals) | new `*.subtitle` |
| Sales regions, Products, Shipping, Shipping zones, Payment methods | reuse existing keys | new `*.subtitle` |
| Product editor (`products.create` / `products.edit`, one component) | `products.editor.title_create` / `title_edit` by `$productId` | new `products.editor.subtitle` |
| Customer detail | the customer's name (at mount) | **none — hook absent** |
| Settings profile / security / appearance | the section (`topbar.settings.profile` / `security` / `appearance`) | a per-section subtitle (`topbar.settings.*_subtitle`); the shared `<h1>Settings</h1>` partial (deleted) and the `sr-only` headings are removed. `x-settings.layout`'s heading/subheading became optional: dropped on Profile and Appearance (their text moved into the topbar), **kept on Security** ("Update password" heads that page's password form, not the page) |
| Media gallery | not applicable (routeless embedded component keeps its inline heading) | — |

### Explicitly NOT in this story

Global search in any form (no field, no placeholder); any change to the bell's content, behaviour, polling or
notification types; breadcrumbs; changes to sidebar navigation or `x-desktop-user-menu`; per-user topbar
preferences; live-updating subtitles; `layouts/app/header.blade.php` (dead code — referenced by nothing; left
alone and noted, **OQ-3**); auth layouts (unauthenticated).

## Tests to perform

**`tests/Feature/Layout/TopbarTest.php`** (full-page `get()`, never `Livewire::test`, which skips the layout)

- [ ] A **dataset of parameterless authenticated GET routes** (15, including `products.create`) plus `customers.show` and `products.edit` with models: each renders exactly one `data-test="topbar"`, one `topbar-title` whose text equals the screen's translated title, and **exactly one** `data-test="notification-bell"` — matched with its closing quote so `notification-bell-unread-indicator` cannot over-count.
- [ ] **Guard test**: every GET route in the `auth` group is in the dataset or in an explicit exclusions list (redirects, public, signed). Proven able to fail by temporarily removing one dataset entry.
- [ ] **Exactly one `<h1>`** per rendered screen (the topbar's), and the removed inline heading text does not appear in the body outside the topbar. Note `flux:heading` without `level` is a `<div>`, so "no second `level=1`" alone would be vacuous: the assertion is the absence of the title text outside `data-test="topbar-title"`. Proven able to fail by temporarily restoring one inline heading.
- [ ] `customers.show`: title is the customer's name and `topbar-subtitle` is **absent**.
- [ ] Every new `lang/en` key exists in `lang/es` (extend the parity check).

**`tests/Browser/Layout/TopbarTest.php`** — each ends with `assertNoJavaScriptErrors()`

- [ ] Wide screen: `[data-test="topbar"] [data-test="notification-bell"]` count is 1, `[data-test="sidebar"] …` count is 0 (add the sidebar hook if missing), **and** the document-wide count is 1 — with loose bounding-box thresholds (bell right edge near the viewport's right, top < 80px) as a second, non-primary check.
- [ ] Phone width (390px): bell visible in the topbar, hamburger toggle and profile dropdown still present, a long title truncates without pushing the bell out.
- [ ] Title + subtitle on dashboard, users, one settings screen (different layout wrapper) and the product editor.
- [ ] **`wire:navigate` from dashboard to users updates the title** (stale-title tripwire; the one browser case for it).
- [ ] Dropdown not obscured: open the bell (also at 390px), `document.elementFromPoint()` at the first row's centre lands inside `[data-test^="notification-item-"]`, the list's bottom edge stays inside the viewport, and a row can be clicked.
- [ ] Every "exactly one" assertion is proven able to fail before it is trusted: temporarily re-add a second bell and a bell inside the sidebar and confirm both go red ([errors-log](../../../docs/errors-log-archive.md#a-count-based-assertion-over-rendered-html-counted-a-wrapper-element-it-never-meant-to-include--2026-08-21)).

**Existing `tests/Browser/Notifications/BellTest.php`** (12 tests): the `visibleCountJs`/`visibleRowHooksJs`/`openVisibleBell` workarounds exist only because the bell was mounted twice — **simplify them to plain hook selectors** (leaving them would hide a regression that re-mounts the bell twice). "Renders on dashboard and users" and "visible at mobile width" are superseded by this story's tests; every other case is location-agnostic and stays. `tests/Feature/Notifications/BellTest.php` is unchanged (`Livewire::test`, never renders the layout).

**Deliberately not tested** (per [what-not-to-test.md](../../../docs/testing/qa/what-not-to-test.md)): pixel-exact placement or colour; Flux dropdown internals; every page's exact copy beyond the representative set; dark mode; global search (none exists); 0057's full behaviour matrix; polling cadence; the exact breakpoint (one wide and one phone width); modals versus the sticky bar.

## Expected outcome

Every authenticated screen has a sticky topbar: the page title and subtitle on the left, the notification bell at the top right, matching the prototype. The bell is mounted exactly once, at every breakpoint, and is gone from the sidebar. Opening it behaves exactly as in 0057. Each screen shows one `<h1>`, in the topbar.

## Acceptance criteria

- [ ] A persistent, sticky topbar renders on **every authenticated screen**, replacing the mobile-only header, with the sidebar toggle and mobile profile dropdown preserved.
- [ ] `<livewire:notifications.bell />` is mounted **once** (in the topbar) and **no bell remains in the sidebar**; the `notification-bell*` hooks are unique per document again.
- [ ] Each screen in the table shows its title in `topbar-title` and (except customer detail) its subtitle in `topbar-subtitle`; the topbar title is the page's **only `<h1>`** and the removed inline headings (including settings') do not reappear.
- [ ] Title/subtitle copy resolves from real `lang/{en,es}` keys (no bare `__('Users')`-style literals), key-for-key identical, with no literal user-facing string in the views.
- [ ] Dropdown opens from the topbar (`position="bottom" align="end"`), fits at 390px, and is not obscured by the sticky bar.
- [ ] Bell behaviour (indicator, mark-all-on-open, list, poll, fallback rendering) is **unchanged** — no change to `Bell.php` logic, 0056's call shapes, routes, `config/modules.php`, `User`, or the `notifications` table.
- [ ] **No global search of any kind** (no field, no placeholder).
- [ ] Every "exactly one" test assertion has been proven able to fail.

## Definition of Done

- [x] Tests written and green, plus the **full** suite run **unscoped** (`vendor/bin/pest`, with `-d memory_limit=-1`; this edits the shared layout and ~15 views, so the blast radius is every rendered page), and `vendor/bin/pint --format agent` (unscoped) + Larastan level 7 clean.
- [x] Code reviewed (code-reviewer) -- PASS, no blocking finding. Confirmed no inline heading duplicates the topbar title. The grep DID catch one live break: `tests/Feature/Customers/ShowRenderingTest.php`'s `assertSee('Ada Lovelace')` (the customer's name moved into the topbar slot, invisible to `Livewire::test()`), fixed to assert only the fields the component still renders (email, phone) and pointed at `TopbarTest.php` for the name.
- [x] No security findings (appsec-auditor) -- PASS. Slot bodies are captured with `{{ }}` (`ComponentSlot` is `Htmlable` and not re-escaped by the layout's own echo, so the encoding must happen where the slot is written); documented as a rule in [security/blade-livewire-output-encoding.md](../../../docs/security/blade-livewire-output-encoding.md#a-layout-slot-is-echoed-unescaped--its-body-must-be-encoded-where-it-is-written).
- [x] Documentation updated (docs-keeper): `docs/api/routes.md` layout-mounted section rewritten (single mount, slot mechanism), [0057 D-1 superseded note](0057-notification-bell-ui.md#documented-functional-decisions), the dead `layouts/app/header.blade.php` recorded, and a new security rule on unescaped slot echoing.
- [x] Acceptance criteria met; PR against `finalproject-ARP`.

## Dependencies, risks, and open questions

### Dependencies

| Depends on | Kind | Why |
| --- | --- | --- |
| [0057](../done/0057-notification-bell-ui.md) | **hard (merged, PR #22)** | Supplies the `Bell` component and hooks this story relocates |

### Risks

- **R-1 — Blast radius.** ~15 views lose their inline heading and the shared layout changes; no existing test asserts these headings (grepped), but `tests/Feature/Navigation/SidebarModuleGatingTest.php` warns "Users" collides with `<title>`. Re-grep per screen before removing a heading.
- **R-2 — Stale title after `wire:navigate`.** Slots render on the full-page render; the topbar must not be `@persist`ed. One browser test pins it.
- **R-3 — Component tests skip the layout.** `Livewire::test(X)->assertSee(<title>)` would break if introduced; topbar assertions therefore live in full-page tests.
- **R-4 — Losing the mobile profile dropdown** when the old header is replaced; covered by the phone-width browser case.
- **R-5 — Bell `wire:poll`/island behaviour** must be re-verified in the new DOM position (the 0057 browser tests re-run unchanged in behaviour).

### Open questions

- **OQ-1 — Dynamic count subtitles.** Resolved: static subtitle in the topbar; existing count summaries stay inline. *(recommended; rejected: a reactive topbar component — over-engineering.)*
- **OQ-2 — Dynamic titles (customer name, product create/edit).** Resolved: static-at-mount slots.
- **OQ-3 — Dead `layouts/app/header.blade.php`.** Non-blocking; leave it and note it. Its removal is a separate cleanup.
- **OQ-4 — Global search.** Still unowned; not decided here.

## Documented functional decisions

**D-1 — One topbar for every breakpoint; the bell is mounted once.** Two mounts were the cause of 0057's duplicated hooks and its visible-instance test helpers; a single sticky `flux:header` removes both and makes the bell visible everywhere.

**D-2 — The topbar title is the page's single `<h1>`.** Title/subtitle are declared per screen as translated named slots; `#[Title]` continues to drive `<title>` only. Inline headings are removed, action buttons stay.

**D-3 — Subtitles are static copy.** A reactive count in the topbar would need a Livewire topbar component; existing count summaries stay inline instead.

**D-4 — Global search is neither built nor reserved.** No placeholder space, so the later search story owns its own layout decision.

**D-5 — No permission gate, route or registry entry**, inherited from [0057 D-4](../done/0057-notification-bell-ui.md#documented-functional-decisions): `auth` via the layout is the whole boundary.

## Provenance

- **Trigger:** project owner's review of [0057](../done/0057-notification-bell-ui.md) against `docs/PRD/images/01-inicio.png`; owner chose scope "bell + title/subtitle" and a separate branch/PR from `finalproject-ARP`.
- **Process:** [workflow.md](../../../docs/workflow.md) Phase 1 — Three Amigos: `frontend-expert` (layout, mechanism, per-screen table, dropdown, dead-layout finding) and `frontend-qa` (Gherkin, hooks, containment assertions, route-dataset + guard, blast radius), composed by `product-owner`.
- **Verified against the working tree** by both participants: `layouts/app/sidebar.blade.php` holds the only header (`lg:hidden`), the bell is mounted twice, `#[Title]` feeds `<title>` only, `layouts/app/header.blade.php` is referenced by nothing.
- **Gherkin conventions:** each scenario opens with a named business-role actor and has exactly one `When`, per [gherkin-guidelines.md](../../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3.
- **Stage:** `done`.
