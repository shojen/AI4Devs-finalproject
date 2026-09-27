# Naming Conventions — Class and file naming

> Part of [Naming Conventions](../naming.md). **Read this part when:** you name a new class, action, controller, listener, notification, enum, policy or exception. The other parts are listed in the [hub](../naming.md#table-of-contents).

## Classes

One class per file, `StudlyCase`, filename matches class name exactly (PSR-4). Verified across `app/Actions/Fortify/`, `app/Livewire/`, `app/Concerns/`:

| Class | File |
| --- | --- |
| `App\Actions\Fortify\CreateNewUser` | `app/Actions/Fortify/CreateNewUser.php` |
| `App\Livewire\Settings\TwoFactor\RecoveryCodes` | `app/Livewire/Settings/TwoFactor/RecoveryCodes.php` |
| `App\Livewire\Actions\Logout` | `app/Livewire/Actions/Logout.php` |

Single-purpose invokable actions are named as an imperative verb phrase, not suffixed with `Action` or `Service`: `Logout`, `CreateNewUser`, `ResetUserPassword`, `RequestEmailChange`, `ConfirmEmailChange` (Fortify's own convention, followed consistently by this app's own actions in `app/Actions/Users/`).

Controllers, by contrast, **are** suffixed `Controller`, and are named after the action they front: `ConfirmEmailChange` (the action) → `ConfirmEmailChangeController` (the invokable controller in front of it). Same for listeners and notifications, which are named as a statement about what happened rather than a command: `ActivateVerifiedUser`, `PendingEmailVerification`. Listeners are auto-discovered from `app/Listeners` (public `handle*` methods with a typed event); never register one by hand.

| Class | File |
| --- | --- |
| `App\Actions\Users\ConfirmEmailChange` | `app/Actions/Users/ConfirmEmailChange.php` |
| `App\Http\Controllers\ConfirmEmailChangeController` | `app/Http/Controllers/ConfirmEmailChangeController.php` |
| `App\Listeners\ActivateVerifiedUser` | `app/Listeners/ActivateVerifiedUser.php` |
| `App\Notifications\PendingEmailVerification` | `app/Notifications/PendingEmailVerification.php` |
| `App\Enums\UserStatus` | `app/Enums/UserStatus.php` |
| `App\Actions\Roles\EnforceAdministratorPermissionGrant` | `app/Actions/Roles/EnforceAdministratorPermissionGrant.php` |
| `App\Actions\Roles\EnforceGrantorPermissionScope` | `app/Actions/Roles/EnforceGrantorPermissionScope.php` |
| `App\Policies\UserPolicy` | `app/Policies/UserPolicy.php` |
| `App\Exceptions\RoleInUseException` | `app/Exceptions/RoleInUseException.php` |
| `App\Actions\Auth\EnsureRecentPasswordConfirmation` | `app/Actions/Auth/EnsureRecentPasswordConfirmation.php` |
| `App\Exceptions\PasswordConfirmationRequiredException` | `app/Exceptions/PasswordConfirmationRequiredException.php` |
| `App\Actions\SalesRegions\SetDefaultSalesRegion` | `app/Actions/SalesRegions/SetDefaultSalesRegion.php` |
| `App\Policies\SalesRegionPolicy` | `app/Policies/SalesRegionPolicy.php` |
| `App\Concerns\SalesRegionValidationRules` | `app/Concerns/SalesRegionValidationRules.php` |
| `App\Actions\Media\GenerateImageConversions` | `app/Actions/Media/GenerateImageConversions.php` |
| `App\Policies\MediaPolicy` | `app/Policies/MediaPolicy.php` |
| `App\Concerns\MediaValidationRules` | `app/Concerns/MediaValidationRules.php` |
| `App\Actions\NormalizeForSearch` | `app/Actions/NormalizeForSearch.php` |
| `App\Exceptions\UnresolvedSelectionException` | `app/Exceptions/UnresolvedSelectionException.php` |
| `App\Livewire\Components\MultiSelectOptionsResolver` | `app/Livewire/Components/MultiSelectOptionsResolver.php` |
| `App\Livewire\Components\SearchableMultiSelect` | `app/Livewire/Components/SearchableMultiSelect.php` |
| `App\Policies\ProductPolicy` | `app/Policies/ProductPolicy.php` |
| `App\Actions\Products\SyncProductSalesRegions` | `app/Actions/Products/SyncProductSalesRegions.php` |
| `App\Actions\Products\ResolveProductTaxRate` | `app/Actions/Products/ResolveProductTaxRate.php` |
| `App\Actions\Products\ResolvedTaxRate` | `app/Actions/Products/ResolvedTaxRate.php` |
| `App\Actions\Products\SearchSalesRegions` | `app/Actions/Products/SearchSalesRegions.php` |
| `App\Enums\TaxRateResolutionTier` | `app/Enums/TaxRateResolutionTier.php` |
| `App\Exceptions\NoDefaultSalesRegionException` | `app/Exceptions/NoDefaultSalesRegionException.php` |
| `App\Policies\ProductAttributeTypePolicy` | `app/Policies/ProductAttributeTypePolicy.php` |
| `App\Actions\Products\SyncProductAttributeValues` | `app/Actions/Products/SyncProductAttributeValues.php` |
| `App\Concerns\ProductAttributeValidationRules` | `app/Concerns/ProductAttributeValidationRules.php` |
| `App\Models\ProductVariant` | `app/Models/ProductVariant.php` |
| `App\Actions\Products\CreateProductVariant` | `app/Actions/Products/CreateProductVariant.php` |
| `App\Actions\Products\UpdateProductVariant` | `app/Actions/Products/UpdateProductVariant.php` |
| `App\Actions\Products\DeleteProductVariant` | `app/Actions/Products/DeleteProductVariant.php` |
| `App\Actions\Products\HashVariantCombination` | `app/Actions/Products/HashVariantCombination.php` |
| `App\Actions\Products\DeriveVariantSku` | `app/Actions/Products/DeriveVariantSku.php` |
| `App\Actions\Products\TranslateProductVariantUniqueViolation` | `app/Actions/Products/TranslateProductVariantUniqueViolation.php` |
| `App\Concerns\ProductVariantValidationRules` | `app/Concerns/ProductVariantValidationRules.php` |
| `App\Actions\Products\GenerateProductVariantCombinations` | `app/Actions/Products/GenerateProductVariantCombinations.php` |
| `App\Actions\Orders\CancelOrder` | `app/Actions/Orders/CancelOrder.php` |
| `App\Exceptions\OrderCancellationBlockedException` | `app/Exceptions/OrderCancellationBlockedException.php` |

Policies are named `<Model>Policy` — and here the name is not merely a convention but a binding: Laravel 13 auto-discovers `App\Policies\UserPolicy` for `App\Models\User` by that exact name, so renaming it silently unbinds every `Gate::authorize()` call against a `User` (see [base-standards.md](../directory-structure.md#directory-structure)). Policy **methods** are named after the ability, as a bare verb phrase in camelCase and without a `can` prefix: `viewAny`, `update`, `promoteToAdministrator`, `updateSensitiveAttributes` — matching how they read at the call site, `Gate::authorize('promoteToAdministrator', $target)`.

Enum cases use TitleCase keys with lowercase backing values — `case Active = 'active';` in `App\Enums\UserStatus`, matching the project `CLAUDE.md` rule. `App\Enums\RoleName` is the exception the rule tolerates: its backing values are the seeded role names *exactly as persisted* (`case Administrator = 'Administrator';`), because the value is compared byte-for-byte against a database row — see [architecture/authorization.md](../../architecture/authorization/administrator-tier.md#the-administrator-tiers-identity).

**Shared identity predicates on a model are named `is<Thing>(self $x): bool` and take the row.** `App\Models\Role` carries three: the `public static` `isAdministratorRole()` and `isSuperAdminRoleRow()`, both of which a *different* class calls with a `Role` in hand, and the `private` `isSuperAdminRole()`, which asks the same question about `$this` from inside the model's own guards. The `Row` suffix on the middle one exists only to disambiguate it from that private sibling; it is a wart, accepted deliberately over renaming a method 0008's guards already depend on. When adding the next one, prefer a name that needs no suffix — and never let a private instance helper and a public static one differ by suffix alone if you can avoid it.
