# Naming Conventions — Translation keys and boolean properties

> Part of [Naming Conventions](../naming.md). **Read this part when:** you add a lang key (including `trans_choice()` plurals and registry-mirroring files) or name a boolean property/predicate. The other parts are listed in the [hub](../naming.md#table-of-contents).

## Translation keys

`lang/<locale>/<domain>.php` — one file per domain area, keys grouped by feature, every segment `snake_case`. Verified in [`lang/en/users.php`](../../../lang/en/users.php) and its Spanish counterpart, which must stay key-for-key identical:

```php
// lang/en/users.php
'statuses' => [
    'active' => 'Active',
    // ...
],

'email_change' => [
    'notification_subject' => 'Confirm your new email address',
    'pending_notice' => 'A change to :email is pending. Use the link sent to that address to confirm it.',
    'confirmed' => 'Your email address has been updated.',
    'refused' => 'This email verification link is no longer valid.',
    'throttled' => 'Too many email change requests. Please try again later.',
],
```

✅ Good — `users.statuses.active`, `users.email_change.throttled`: domain file, feature group, snake_case leaf. Values that interpolate use Laravel's `:placeholder` form (`:email`).
❌ Bad — do not write `users.emailChange.throttled` (camelCase segment), a flat `users.email_change_throttled` (no group), or a literal string inline in a component instead of a key. `App\Enums\UserStatus::label()` resolves `__('users.statuses.'.$this->value)` by convention, so a status label that isn't in the `statuses` group renders as its own raw key.

**A `label()` method on an enum is not automatic — an enum with one rendering site keeps its copy in that screen's own lang file.** `UserStatus::label()` exists because the status is rendered in more than one place and by the enum's own `cases()` loop. [`App\Enums\SalesRegionKind`](../../../app/Enums/SalesRegionKind.php) deliberately has **no** `label()` even now that task 0018 renders it: `kind` drives structure everywhere on the Sales Regions list (indentation, chevron, grouping) and is surfaced as text in exactly one place, the edit modal's read-only context block, so the two labels live in that screen's own file as `sales-regions.labels.kind_country` / `kind_fiscal_territory` and the view matches on the case. Story 0016 deferred `label()` to "the first story that actually renders `kind`"; 0018 is that story and declined it, which is the decision to copy — **add `label()` when a second consumer appears, not when the first one does**, because a one-caller `label()` is indirection that hides which lang group owns the copy. Note the leaves are still snake_case (`kind_fiscal_territory`) even though the enum's backing value is too — the match is a coincidence of this enum, not a rule to rely on.

**A count-dependent message is one key with a `|`-delimited plural form, resolved with `trans_choice()` — never two keys and never a hand-built `$count === 1 ? … : …`.** [`lang/en/roles.php`](../../../lang/en/roles.php) (task 0010) is the first one:

```php
// lang/en/roles.php
'index' => [
    'delete_blocked' => 'This role cannot be deleted while it is still held by :count user.|This role cannot be deleted while it is still held by :count users.',
],
```

```php
// app/Livewire/Roles/Index.php — deleteRole()
trans_choice('roles.index.delete_blocked', $role->users_count, ['count' => $role->users_count])
```

✅ Good — the singular/plural split lives in the *translation file*, so a locale with different plural rules (Spanish here, and any future one) can express them without touching PHP.
❌ Bad — `delete_blocked_one` / `delete_blocked_many` as two keys, or branching on the count in the component. Both hardcode English's two-form plural rule into code that other locales have to live with.

**Six `trans_choice()` keys exist in this codebase as of story 0024b, in two different established forms — neither is "the newer one", and both have coexisted since task 0019.** `lang/en/roles.php` (task 0010, extended by task 0011) has three keys in the **simple `singular|plural`** form shown above — `index.delete_blocked`, `index.summary`, `index.permission_count`. `lang/en/media.php` (story 0019) has two keys in a different, **explicit-range** form instead — `gallery.count_summary`, `gallery.selection_count` — because both of those need to express a genuine **zero**-count case (`{0} No images|{1} :count image|[2,*] :count images`) that the simple form's `count === 1 ? first : second` selection cannot represent. `lang/en/products.php`'s `categories.delete_blocked` (story 0024b) is the **sixth** key overall, and it goes back to the **simple** form: the guard it renders for (`App\Actions\ProductCategories\DeleteProductCategory`) only ever throws once the count is already positive, so there is no zero case to express and reaching for the explicit-range form would buy nothing.

✅ Good — the choice between the two forms is not precedent order, it is **which of the two already-established forms fits the message's own semantics**: does the message ever need to render at `count === 0`? If yes, explicit-range (`media.php`'s shape); if the count is guaranteed positive by the code path that renders it, simple `singular|plural` (`roles.php`'s and now `products.php`'s shape).
❌ Bad — treating this as "there are two forms, so pick whichever" without checking the zero-case question, or (a mistake this story's own task file caught at Phase 2 review) describing a new simple-form key as "the second `trans_choice` key, the first outside `roles.php`" — that undercounts by four the moment `media.php`'s two explicit-range keys are counted too.

**The rule binds a Blade template exactly as it binds a component.** Task 0011 added two more `trans_choice()` keys to the same group — `roles.index.summary` (the list's live role count) and `roles.index.permission_count` (each row's granted-permission count) — and the second one shipped its first draft with `':count permission|:count permissions'` written **inline in `resources/views/livewire/roles.blade.php`**, where `lang/es/roles.php` could never reach it (Phase 5 finding F-3). A hardcoded plural is no more acceptable in a view than in PHP; the giveaway is the `|` character appearing anywhere outside a `lang/` file.

**A key leaf is `snake_case` even when the value it names is not — map at render, never rename the value.** This story is where the two collide: the permission catalog's own names are `<module-slug>.<action>` with kebab-case segments (`sales-regions.view`, `roles.manage-administrators`), and those names are fixed by the seeded catalog. The labels are therefore **composed** from two flat arrays rather than written one key per permission:

```php
// lang/en/roles.php — top-level siblings of 'index', not nested under it
'modules' => ['users' => 'Users', 'sales_regions' => 'Sales regions', /* … */ 'roles' => 'Roles'],
'actions' => ['view' => 'View', /* … */ 'manage_administrators' => 'Manage administrator-level roles/users'],
```

```blade
{{-- resources/views/livewire/roles.blade.php --}}
__('roles.modules.'.str_replace('-', '_', $module)).' — '.__('roles.actions.'.str_replace('-', '_', $action))
```

✅ Good — 17 keys per language (10 module labels + 7 action labels, since story 0051 added `refund`) covering all 43 seeded permissions, the hyphen mapped to an underscore at the point of lookup, and a new seeded module needing exactly one new key.
❌ Bad — `'sales-regions' => …` as a literal kebab-case key leaf (violates the rule above), one key per permission (43+ keys, and a catalog addition silently renders a raw key), or renaming the permission itself to match the key. The permission name is the database's, not the translation file's.

> **⚠️ Corrected 2026-08-29 — this warning said story 0019 had shipped the ❌, and it had not.** As written, it claimed that story appended the tenth module slug `media` and *"did **not** add a `roles.modules.media` leaf to either locale"*, so the Roles matrix rendered the raw key in both languages. That is false: `lang/en/roles.php` carries `'media' => 'Media'` and `lang/es/roles.php` carries `'media' => 'Medios'`, both present in story 0019's own tree and untouched by any story since — verified by reading both files, which is what the original claim says it did. It is the second false "verified" finding from that pass; [errors-log.md](../../errors-log/2026-08-28-to-2026-08-31.md#one-docs-pass-reported-two-gaps-that-were-not-there-both-marked-verified--2026-08-29) records why one docs pass produced two. **Everything below the correction is the rule, and the rule is unchanged** — it is exactly *because* nothing fails that it is worth stating: the four `media.*` permissions would be seeded, grantable and enforced correctly with or without the leaf, `__()` returning its own key is not an error condition, and the seeder tests assert names and counts rather than rendered copy, so a missing leaf is **only** visible by looking at the screen. `media` is now the evidence the rule is followable, not the evidence it gets missed. Two rules follow. **(a) Adding a module slug means adding its `roles.modules.<slug>` leaf to `lang/en/` *and* `lang/es/` in the same change** — the label is not derived, and `__()` returning its own key is not an error condition. **(b) The `roles.actions.*` half needs nothing**, because a new module reuses the four existing CRUD verbs; only a genuinely new *action* segment would need a leaf there. Note `media` is a single lowercase word, so its leaf is `media` with no mapping — the `str_replace('-', '_', …)` step only matters for a kebab-case slug like `sales-regions`.

**When a lang file exists to supply copy for a registry, its key structure mirrors the registry's own keys exactly — one leaf per registry key, no extras, no renames.** [`lang/en/navigation.php`](../../../lang/en/navigation.php) (task 0013) is the first such file, and the mirroring is what makes it reviewable. Its original shape, at task 0013, was `config/modules.php`'s `groups.platform` / `groups.settings` / `groups.taxes` and `items.dashboard` / `items.users` / `items.roles` / `items.sales_regions` — seven leaves under two headings. **That is no longer the registry's current shape.** Story 0080's registry restructuring retired `groups.platform` and `groups.taxes` entirely (not merely renamed them) and added a third registry array, `clusters`, alongside `groups` and `items` — a lang file supplying copy for a registry still mirrors it exactly, now across all three arrays: `groups.store` / `groups.content` / `groups.settings`, `clusters.products` / `clusters.store_settings` / `clusters.blog`, and `items.dashboard` / `items.users` / `items.roles` / `items.sales_regions` / `items.product_categories` / `items.products` / `items.product_attribute_types` / `items.blog_tags`. See [`config/modules.php`](../../../config/modules.php) and [architecture/authorization.md](../../architecture/authorization/how-to-gate.md#the-second-half-of-a-module-gate-the-sidebar-registry) for the real, shipped shape — the mirroring rule itself, and the rest of this section, are unchanged by the restructuring.

```php
// lang/en/navigation.php — the leaves are config/modules.php's own array keys
'groups' => ['platform' => 'Platform', 'settings' => 'Settings', 'taxes' => 'Taxes'],
'items' => ['dashboard' => 'Dashboard', 'users' => 'Users', 'roles' => 'Roles & permissions', 'sales_regions' => 'Sales Regions'],
```

✅ Good — a registry key is simultaneously the translation leaf and the rendered `data-test` hook (`data-test="sidebar-link-roles"`), so one identifier connects the config entry, its copy, and the test that asserts on it. Adding a module means adding the same leaf to `lang/en/` and `lang/es/`, and nothing else.
❌ Bad — writing the copy into the registry itself (`'label' => 'Roles & permissions'` in `config/modules.php`), which puts a literal English string somewhere `lang/es/` cannot reach; or naming the leaf differently from the registry key (`items.roles_and_permissions` for the `roles` entry), which breaks the one-identifier property for no gain. Note this rule does **not** conflict with the snake_case rule above.

**Since task 0018 the multi-word case is shipped rather than hypothetical, and this paragraph is the sentence it was written against.** From task 0013 until then, every registry key was a single lowercase word (`dashboard`, `users`, `roles`), so nothing forced the decision and this text read forward: *"a future registry key that is genuinely multi-word is snake_case on both sides (`items.sales_regions`), never kebab-case."* The Sales Regions entry is that key, and it went in as `sales_regions` (story 0060's `blog_tags` is the second, following the identical convention) — verified in `config/modules.php`, both `navigation.php` files, and the rendered `data-test="sidebar-link-sales_regions"` hook that `tests/Feature/Navigation/SidebarModuleGatingTest.php` selects. Three identifiers move together, so getting the key wrong breaks all three at once.

The **values** inside that same entry stay kebab-case, and the distinction is the whole rule: `'permissions' => ['sales-regions.view']` is a seeded permission name, `'route' => 'sales-regions.index'` a route name, `'current_when' => 'sales-regions.*'` a route pattern. None of the three is a registry *key*, and each is owned by something outside this file — the seeded catalog and `routes/sales-regions.php` — exactly like the *permission* names above, whose kebab-case is imposed by the catalog and mapped at lookup.

Note `APP_LOCALE=en` today, so everything renders in English until the interface language switcher exists — an accepted, documented consequence of the English-source decision, not a defect. Adding a key means adding it to **both** `lang/en/` and `lang/es/` in the same change.

**Exception: a validation `attributes` block's leaf is the field name, byte-for-byte, even when that name is camelCase.** [`lang/en/sales-regions.php`](../../../lang/en/sales-regions.php) (task 0017) is this repo's first `attributes` block — the array Laravel's `validate(..., attributes: __(...))` uses to substitute a human label for `:attribute` in a validation message. Its `replacementDefaultId` leaf is camelCase because it must equal `App\Livewire\SalesRegions\Index::$replacementDefaultId`'s own property name exactly, or Laravel silently fails to find the override and falls back to the raw field name. This is not a violation of the snake_case-leaf rule above — it is a different kind of key entirely, one Laravel itself defines the shape of, the same way a route parameter name or a Blade component prop name is never snake_cased just because it appears in a `lang/` file. Do not "fix" a camelCase `attributes` leaf to snake_case; doing so breaks the substitution instead of correcting a style slip.

## Boolean properties

Livewire component boolean properties are named as a predicate, prefixed `can`/`is`/`show`/`requires` — never a bare noun. Verified in `app/Livewire/Settings/Security.php`:

```php
public bool $canManageTwoFactor;
public bool $canManagePasskeys;
public bool $twoFactorEnabled;      // present-tense state, not prefixed — see note below
public bool $requiresConfirmation;
public bool $showModal;
public bool $showVerificationStep;
public bool $showDeleteModal;
```

Two patterns coexist in this file: `can*`/`requires*`/`show*` for capability/UI-state flags, and a bare past-participle (`twoFactorEnabled`) for a fact about the authenticated user's current state. Follow whichever of the two fits: use `can`/`requires`/`show` for UI/permission flags you're introducing, and a plain past-participle only for a mirrored model/domain fact (as `twoFactorEnabled` mirrors `User::hasEnabledTwoFactorAuthentication()`).

**The same rule binds a `#[Computed]` boolean method**, which is what a modern Livewire screen actually exposes to its view. `App\Livewire\Users\Index` carries four (task 0015a): `requiresPasswordConfirmation()`, `isEditingOwnRow()`, `isDeletingOwnRow()`, `isAdministratorRoleSelected()` — a predicate name, never a noun (`passwordConfirmation()`), and never a `get*` prefix.

**Name the predicate so it is unambiguous read *out* of its class.** `App\Actions\Auth\EnsureRecentPasswordConfirmation`'s non-throwing method is `isRecentlyConfirmed()`, deliberately not `isConfirmed()`: at the call site (`app(EnsureRecentPasswordConfirmation::class)->isRecentlyConfirmed()`) the class name supplies "password", but *recency* is the whole content of the check and a bare `isConfirmed()` reads as a yes/no about whether the password was ever confirmed at all. The invokable's own name follows the existing imperative-verb-phrase rule for actions (`Ensure…`, no `Action`/`Service` suffix), so the throwing and non-throwing halves of one rule read as a command and a question respectively.

`App\Models\Order::isManuallyCancellable()` (story 0050) is the case where the *adverb*, not the noun, carries the meaning. A bare `isCancellable()` would read as a complete answer today — but story 0052's 100%-refund auto-cancel cancels an order **regardless of its current state**, deliberately never consulting this predicate at all, so a name without "manually" would become a lie the moment that story ships: two different, uncoordinated cancellation paths would then both plausibly claim the unqualified name. Naming the predicate after the *actor path* it governs (a human clicking cancel, not a system side effect) rather than after the operation alone is what keeps the two paths distinguishable by name once both exist.

`Order::isLineItemEditable()` and `Order::isRefundable()` (story 0055) follow the same shape: non-throwing, answering about the row only (never the actor), each named after the thing the actions and the order screen both ask ("may line items change", "may a refund be taken") rather than after one caller.

_Last updated: 2026-09-23 — Story 0055 (orders list + detail/editor UI). Added `App\Livewire\Orders\Index` to the `Index`-in-a-subfolder table and the third documented instance of the `Index`-flat / sibling-nested depth asymmetry, and `Order::isLineItemEditable()`/`isRefundable()` to the predicate-naming notes. Earlier history folded: 0050 added `CancelOrder`/`OrderCancellationBlockedException` to the Classes table and the `isManuallyCancellable()` naming note; 0051 added `ORDER_PERMISSIONS` (`orders.refund`) to the Permission names block; 0044 added the Customers row; a doc-growth pass condensed the traits and permission-policy narratives. Each is described in its own section above._
