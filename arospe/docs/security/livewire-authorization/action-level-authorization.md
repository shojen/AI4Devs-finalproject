# Livewire Component Authorization — Authorization in the action, not only the component

> Part of [Livewire Component Authorization](../livewire-authorization.md). **Read this part when:** you extract or call an action and must ensure the rule binds every other caller (jobs, Artisan, tests). The other parts are listed in the [hub](../livewire-authorization.md#table-of-contents).

## Authorization that lives only in the component is bypassed by every other call site of the action

The rule for this repo: **a privilege rule about *which* role may be assigned belongs in the action,
the policy, or the validation rule set — not in the screen that happens to be the only caller today.**
A Livewire component is a delivery mechanism; an Artisan command, a queued job, a future REST
controller or a sibling screen calling the same action inherits none of its `Gate::authorize()` calls.

**Task 0004's audit recorded this as an open gap on the Users screen; task 0008a closed it.** The
before/after is worth keeping, because the *shape* of the gap recurs on every module screen:

| Control | Where it lived (task 0004) | Where it lives now (task 0008a) |
| --- | --- | --- |
| "Super Admin is never assignable" | `UserValidationRules::roleRules()` — survives, if the caller validates | **also** a direct `throw` in `CreateUser` / `UpdateUser`, which survives regardless |
| "a Super Admin holder is not editable" | `UserPolicy::update()` — survives, if the caller gates | **also** a direct `throw` in `UpdateUser`, which additionally binds a Super Admin actor |
| "adding/removing `Administrator` needs `roles.manage-administrators`" | ❌ `App\Livewire\Users\Index::authorizeRoleChange()` only | ✅ `UpdateUser` / `CreateUser` themselves; the component method is deleted |
| "changing an `Administrator`'s `status`/`email` needs `roles.manage-administrators`" | ⚠️ the ability travelled (`UserPolicy::updateSensitiveAttributes()`) but the **decision that a change occurred** stayed in the component | ✅ both live in `UpdateUser`, which compares against `getRawOriginal()` |

That last row was the finer-grained case and the instructive one: pushing the *rule* into a policy is
not enough while the **trigger** — comparing the submission against stored state to decide whether the
ability applies at all — stays in the caller. A second caller inherits an ability nothing ever asks.

Two rules to carry forward, both proven by how this was closed:

- **Move the check, don't duplicate it.** The component still authorizes `create` / `update` / `delete`
  at its own call sites (defence in depth, and `deleteUser()` calls no action at all), but there is
  exactly **one** implementation of each tier rule. Two copies drift.
- **A caller-supplied flag is not a guard.** `UpdateUser` used to receive the self-lockout boolean from
  the component; it now derives it from `Auth::user()` itself. See
  [conventions/base-standards.md](../../conventions/directory-structure/controllers-and-authorization-rule.md#an-authorization-rule-belongs-to-the-action-not-to-one-of-its-callers)
  for the convention, and
  [authorization-patterns.md](../authorization-patterns/ability-coverage-and-guards.md#a-rule-that-must-bind-a-super-admin-actor-must-be-a-direct-throw-not-a-gate-check)
  for why the two Super Admin refusals are direct throws rather than `Gate` checks.

## An unguarded write primitive is reached only through a self-authorizing, self-validating per-entity wrapper

Story 0070's `App\Actions\Translations\SetTranslation` deliberately neither authorizes nor validates:
the right ability belongs to the calling operation (`create` for `CreateProductCategory`, `update` for
`RenameProductCategory`). Story 0071 set the pattern the sibling translation stories (0073/0075/0077/0079)
copy. Confirmed safe in the 0071 audit:

- **Only per-entity actions import the primitive.** No `app/Livewire/` file may import it. Check with
  `grep -rn "Translations\\\\SetTranslation" app/`. Story 0071 has three importers, all in
  `app/Actions/ProductCategories/`. Story 0073 repeats the pattern for blog categories:
  `App\Actions\Blog\SetBlogCategoryTranslation` (logged `update` first, then nested-data validation keyed
  `names.{languageId}`, 1062 race mapped by `TranslateBlogCategoryNameUniqueViolation`) is the only
  importer in `app/Actions/Blog/`, and `BlogCategories\Index` (layer 1: authorize, validate the whole
  batch, one transaction) never imports the primitive. The 0073 audit found no issue; its three
  informational notes are accepted by design: the action accepts an inactive or default language
  (the component only passes active ones), it does not re-read the passed instance, and `$names`
  is unbounded by key count (save() iterates the server-queried languages, so extra keys are ignored).
  Story 0074 (blog tags) adds three more importers in `app/Actions/Blog/` — `CreateBlogTag`,
  `RenameBlogTag`, `FindOrCreateBlogTag` — each calling the primitive only *below* its own
  authorization gate (`create`/`update`, or `viewAny`/`create` for find-or-create), and only for the
  default language. Story 0075 adds the per-language tag wrapper `App\Actions\Blog\SetBlogTagTranslation` (logged `update` on the tag via `target_type: 'blog_tag'` first, then it re-reads the tag and the language through `StoreLanguage::query()->active()`, **refuses an inactive language** with a `names.{languageId}` `ValidationException`, validates on its own and re-keys the 1062). It is the only tag importer of the primitive besides the three above; no Livewire file imports it. The inactive-language refusal is a deliberate divergence from the product/blog category wrappers above, which accept one and pin it in tests; story 0087 proposes aligning them. Story 0076b adds the product counterpart `App\Actions\Products\SetProductTranslation` (logged `update` on the product via `target_type: 'product'` first, then it re-reads the product and the language through `StoreLanguage::query()->active()`, **refuses an inactive or unknown language** with a `names.{languageId}` `ValidationException`, sanitises the description and canonicalises the slug before validating nested data, and re-keys the 1062 through `TranslateProductUniqueViolation` to `slugs.{id}`/`names.{id}`); like the tag wrapper it does not follow the category siblings' accept-inactive behaviour. Story 0079 adds `App\Actions\Blog\SetBlogPostTranslation` (logged `update` on the post via `target_type: 'blog_post'` first, re-reads the post, sanitizes and size-guards the body, validates on its own with error keys derived from the language, re-keys the 1062 slug collision onto `titles.{id}`); `Blog\Editor::save()` is its only caller and an architecture test (`tests/Unit/BlogPostTranslationArchitectureTest.php`) fences `SetTranslation` out of `app/Livewire`. **Accepted residual L-1:** unlike the tag and product wrappers it does not refuse an inactive store language inside the action (only the tab strip filters); story 0087 owns that alignment. `BlogTags\Index::save()` authorizes the whole batch before opening its transaction, so no refusal is logged inside it (and the log is `Log::warning`, outside the database, so a rollback would not erase it anyway).
- **The wrapper authorizes first, logs the refusal, then validates on its own.**
  `SetProductCategoryTranslation::__invoke()` calls `LogRefusedPrivilegedAttempt::authorize('update', …)`
  as its first statement. It validates nested data (`['names' => [$language->id => $name]]`) against
  the dotted rule key, and derives the error key from `$language->id`. The caller never passes that key.
- **The component iterates server-side ids, never the client array's keys.** `$names` is unlocked
  because `wire:model` writes it. `save()` loops over the active languages it queries itself, coerces a
  non-string value to `''`, and so ignores a forged key for an unknown or inactive language. Anything
  that drives a decision is `#[Locked]`. Here that is `$originalTranslatedLanguageIds`, which decides
  whether a blank tab is refused.
- **An ability beyond the branch's own needs its own logged check, before the transaction.** On the
  create branch, `products.create` covers only the default-language row. A non-blank non-default value
  also needs `update`. `save()` checks it, and logs a refusal, before validation and before
  `DB::transaction()`, so a forged value writes nothing. The wrapper re-checks `update` inside the
  transaction as the second layer.

_Last updated: 2026-11-12 — Story 0079: the wrapper section lists `SetBlogPostTranslation` (target_type `blog_post`) and records residual L-1 (no inactive-language check, story 0087). Still current from story 0076b (`SetProductTranslation`) and story 0074._
