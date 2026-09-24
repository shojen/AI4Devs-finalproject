# Naming Conventions — Livewire components and views

> Part of [Naming Conventions](../naming.md). **Read this part when:** you add a Livewire component and must place its view (the `Index`-in-a-subfolder exception). The other parts are listed in the [hub](../naming.md#table-of-contents).

## Livewire components and views

Component class is `StudlyCase`; its Blade view is the **kebab-case** version of the class name, in a mirrored directory structure under `resources/views/livewire/`:

| Component | View |
| --- | --- |
| `App\Livewire\Settings\Security` | `resources/views/livewire/settings/security.blade.php` |
| `App\Livewire\Settings\Profile` | `resources/views/livewire/settings/profile.blade.php` |
| `App\Livewire\Settings\DeleteUserForm` | `resources/views/livewire/settings/delete-user-form.blade.php` |

✅ Good — `DeleteUserForm` → `delete-user-form.blade.php` (each word boundary becomes a hyphen).
❌ Bad — do not use `deleteuserform.blade.php` or `DeleteUserForm.blade.php`; Livewire's convention-based view resolution expects the kebab-case mirror.

### Exception: a component named `Index` resolves to its **parent folder's** name

The mirror rule above has one exception, and it is Livewire's, not this project's. A component class named `Index` inside a subfolder drops the `.index` segment entirely and resolves to the **subfolder name**:

| Component | View — actual | View — what the mirror rule would predict |
| --- | --- | --- |
| `App\Livewire\Users\Index` | `resources/views/livewire/users.blade.php` | ~~`resources/views/livewire/users/index.blade.php`~~ |
| `App\Livewire\Roles\Index` | `resources/views/livewire/roles.blade.php` | ~~`resources/views/livewire/roles/index.blade.php`~~ |
| `App\Livewire\SalesRegions\Index` | `resources/views/livewire/sales-regions.blade.php` | ~~`resources/views/livewire/sales-regions/index.blade.php`~~ |
| `App\Livewire\Products\Index` | `resources/views/livewire/products.blade.php` | ~~`resources/views/livewire/products/index.blade.php`~~ |
| `App\Livewire\Products\AttributeTypes\Index` | `resources/views/livewire/products/attribute-types.blade.php` | ~~`resources/views/livewire/products/attribute-types/index.blade.php`~~ |
| `App\Livewire\Customers\Index` | `resources/views/livewire/customers.blade.php` | ~~`resources/views/livewire/customers/index.blade.php`~~ |
| `App\Livewire\Orders\Index` | `resources/views/livewire/orders.blade.php` | ~~`resources/views/livewire/orders/index.blade.php`~~ |

This is explicit in the installed vendor source:

```php
// vendor/livewire/livewire/src/Finder/Finder.php — Finder::generateNameFromClass()
// If using an index component in a sub folder, remove the '.index' so the name is the subfolder name...
if ($fullName->endsWith('.index')) {
    $fullName = $fullName->replaceLast('.index', '');
}
```

So `App\Livewire\Users\Index` becomes the component name `users`, and `users` resolves to `livewire/users`. The nested path is still *offered* as a fallback (`Finder` also probes `<folder>/index.blade.php` and `<folder>/<folder>.blade.php`), but the flat file is what this repo uses and what a reader should expect to find.

✅ Good — the real pairing in this repo: `app/Livewire/Users/Index.php` ↔ `resources/views/livewire/users.blade.php`.
❌ Bad — assuming the mirror rule holds and looking for (or creating) `resources/views/livewire/users/index.blade.php`. It is not the path Livewire reports as the component's view, and a second file there is a silently unused duplicate.

The third row (task 0017) adds the one thing the first two could not show: **the subfolder name is kebab-cased on the way down**, so a multi-word area segment splits — `App\Livewire\SalesRegions\Index` resolves to `livewire/sales-regions.blade.php`, not `livewire/salesregions.blade.php`. That is the ordinary mirror rule applied to the *folder* name after `.index` is stripped, but `Users` and `Roles` are single words and demonstrated none of it.

**This has already cost real time once — twice now — so it is worth stating as a habit rather than a rule to recall.** Task 0010's own Phase 1 spec — and its sibling 0011's — both wrote the nested path for `App\Livewire\Roles\Index`, and the error surfaced only when the story's test suite ran and threw `Illuminate\View\ViewException: File does not exist at path .../resources/views/livewire/roles.blade.php`. Livewire never even probes the nested path first, so nothing hints at the mistake until something renders. When adding an `Index` component, resolve the view path **by running the component**, not by reasoning about it.

Task 0017 hit the *other* half of the same trap, and it is worth knowing because it costs nothing to walk into: its task file quoted the rule correctly and its component was written to the flat path, but an `artisan make:` scaffold still deposited an unused `resources/views/livewire/sales-regions/index.blade.php` stub on disk. Nothing failed — the flat view resolved, the tests passed, and the stub simply sat there as a silently-unused duplicate until it was noticed and removed (verified: only `resources/views/livewire/sales-regions.blade.php` exists today). **So the check is not only "did I write the right path" but "is there a second file at the wrong one".**

Practical consequence when adding the next module screen: an `Index` component for a new area lands at `resources/views/livewire/<area>.blade.php`, one level *shallower* than its class. Any other component in that same subfolder follows the normal mirror rule, so the two live at different depths — that asymmetry is expected, not a mistake. **Story 0027 is the first real instance of this, replacing the hypothetical `App\Livewire\Users\Editor` this paragraph used to cite** (`Users` has no `Editor` component — its create/edit form is a modal on `Index` itself, not a second class): `App\Livewire\Products\Index` → `resources/views/livewire/products.blade.php` (the `Index`-in-a-subfolder exception, flat, the table's **fourth** row) sits one level shallower than its sibling `App\Livewire\Products\Editor` → `resources/views/livewire/products/editor.blade.php` (the ordinary mirror rule, nested) — same folder, two different view depths, exactly as predicted.

**Story 0047 is the second real instance, in a different module, confirming this is a general property of the exception rather than a one-off in `Products/`.** `App\Livewire\Customers\Index` → `resources/views/livewire/customers.blade.php` (flat, the table's **sixth** row, already listed above) and its new sibling `App\Livewire\Customers\Show` → `resources/views/livewire/customers/show.blade.php` (the ordinary mirror rule, nested — `Show` is not named `Index`) sit in the same `Customers/` folder at two different view depths, the identical shape `Products/`'s `Index`/`Editor` pair established. Unlike `Products\Editor`, `Customers\Show` is not a create/edit form — it is a new, third kind of sibling this exception had not yet paired an `Index` with: a read-only detail page, reached via a plain list-row link (`:href`, not a `wire:click`) rather than a modal opener.

**Story 0055 is the third documented instance, and the second `Index`/`Show` pair.** `App\Livewire\Orders\Index` → `resources/views/livewire/orders.blade.php` (flat, the table's **seventh** row) and `App\Livewire\Orders\Show` → `resources/views/livewire/orders/show.blade.php` (nested) repeat `Customers/`'s shape exactly, in a different module. Counted from this file: `Products/` (`Index`/`Editor`), `Customers/` (`Index`/`Show`) and `Orders/` (`Index`/`Show`); `Shipping/` (`Index`/`Zones`) has the same shape but is not written up here. `Orders\Show` differs from `Customers\Show` in being the app's first detail screen that also writes, which the mirror rule does not care about.

The **fifth** row (story 0028) is the first **two-level-deep** subfolder before an `Index` class, and it confirms the mechanism generalises rather than needing a special case: `Finder::generateNameFromClass()` strips only the trailing `.index` segment, so `App\Livewire\Products\AttributeTypes\Index` becomes the component name `products.attribute-types` (both remaining segments kebab-cased independently — `AttributeTypes` → `attribute-types`, not `attributetypes`), which resolves to the flat `livewire/products/attribute-types.blade.php` — one level shallower than the class's own three-segment namespace, never `livewire/products/attribute-types/index.blade.php`. Verified by running the component (per the habit two paragraphs above), not by reasoning about it from the vendor source alone.

**Story 0019 is the first real instance of that "any other component" case, and it is worth naming because the exception above is memorable enough to be over-applied.** `App\Livewire\Media\Gallery` resolves to `resources/views/livewire/media/gallery.blade.php` — the **normal** mirror rule, nested, because the class is not named `Index`. The exception keys on the class name, never on the component living in a subfolder. Note the story's own task file had to state this explicitly to stop the mistake being made in the other direction, which is the tell that the exception has become the thing people remember.

Story 0021 is the second confirmation: `App\Livewire\Components\WysiwygEditor` → `resources/views/livewire/components/wysiwyg-editor.blade.php`, the ordinary mirror rule again, for the identical reason — the class is not named `Index`, and living inside a subfolder that is itself not a module area (`Components/`, per [base-standards.md](../directory-structure.md#directory-structure)) changes nothing about which rule applies.

Story 0022 is the third: `App\Livewire\Components\SearchableMultiSelect` → `resources/views/livewire/components/searchable-multi-select.blade.php`. The sibling `MultiSelectOptionsResolver` in the same folder is a plain interface with no view of its own, and is not subject to this rule at all — the mirror rule (and its exception) governs a Livewire `Component` subclass, not every file that happens to live under `app/Livewire/`.

Story 0031 is the fourth: `App\Livewire\Products\VariantBuilder` → `resources/views/livewire/products/variant-builder.blade.php`, the ordinary mirror rule once more, for the same reason as the three before it — the class is not named `Index`. What is new about this instance is not the naming rule but what the component *is*: this app's first **nested child** component embedded inside another module's own routed page (`<livewire:products.variant-builder :product-id="$productId" .../>` inside `resources/views/livewire/products/editor.blade.php`) rather than mounted at a route or a modal of its own — and the mirror rule does not care. `App\Livewire\Products\Editor` (story 0027, already the ordinary-rule instance the "practical consequence" paragraph above cites) and `VariantBuilder` now sit in the same `Products/` folder at the same nesting depth, both following the plain `<class-path>` ↔ `<kebab-case-path>.blade.php` pairing with no `Index`-exception in sight.

Note: `resources/views/livewire/auth/*.blade.php` (login, register, forgot-password, etc.) are **plain Blade views**, not Livewire components — they live under `livewire/` for directory consistency but are bound directly as Fortify's auth views, e.g. `Fortify::loginView(fn () => view('livewire.auth.login'))` in [`app/Providers/FortifyServiceProvider.php`](../../../app/Providers/FortifyServiceProvider.php). Don't assume every file under `resources/views/livewire/` has a matching PHP component class — check for one before citing it.
