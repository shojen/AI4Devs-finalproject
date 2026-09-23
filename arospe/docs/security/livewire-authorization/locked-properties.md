# Livewire Component Authorization — #[Locked] properties and gate/display twins

> Part of [Livewire Component Authorization](../livewire-authorization.md). **Read this part when:** you add a public property to a Livewire component (which must be `#[Locked]`), use `Rule::unique()->ignore()`, or pair a save-time gate with its display hint. The other parts are listed in the [hub](../livewire-authorization.md#table-of-contents).

## `#[Locked]` is what makes `Rule::unique()->ignore()` safe here

Laravel's validation documentation is explicit that user-controlled input must never reach
`Rule::unique()->ignore()`. The edit path here passes `$this->editingUserId` straight into it:

```php
// app/Concerns/ProfileValidationRules.php
Rule::unique(User::class)->ignore($userId)
```

```php
// app/Livewire/Users/Index.php
$validated = $this->validate([
    ...$this->profileRules($this->editingUserId),
    // ...
]);
```

That is safe for exactly two reasons, and both must hold:

1. `#[Locked] public ?string $editingUserId` — Livewire throws
   `CannotUpdateLockedPropertyException` on any client attempt to change it, so it is not request
   input.
2. The only writer is `openEditModal()`, and it assigns `$target->id` — a value read back **out of
   the database** (`User::findOrFail($userId)->id`), never the raw method argument.

If either changes — the attribute is dropped, or the id is assigned straight from the argument — the
`ignore()` call becomes a user-controlled value again. Treat those two lines as a pair.

> ✅ **Confirmed on a fourth screen, story 0025.** `App\Livewire\ProductCategories\Index` carries the
> identical pair: `#[Locked] public ?string $editingCategoryId`, written only from
> `openEditModal()`/`save()` as `$target->id` — `ProductCategory::findOrFail($categoryId)->id`, never
> the raw method argument — and consumed by
> `ProductCategoryValidationRules::uniqueNormalisedName()`'s `Rule::unique(...)->ignore($productCategoryId)`.
> A dedicated retarget test (`->call('openEditModal', $a->id)->set('editingCategoryId', $b->id)`)
> asserts the client-side write throws `CannotUpdateLockedPropertyException` rather than silently
> retargeting the uniqueness check onto `$b` — the same test shape `Users\Index`'s and
> `SalesRegions\Index`'s equivalents already use for their own locked ids.

> ✅ **Confirmed on a fifth screen, story 0027.** `App\Livewire\Products\Editor` carries the identical pair: `#[Locked] public ?string $productId`, written only inside `mount()` as `$product->id` — the route-model-bound instance's own id, never a client argument — and consumed by `ProductValidationRules::productSkuRules($this->productId)`'s `Rule::unique('products', 'sku')->ignore($productId)`. A dedicated retarget test pins the pair, matching `Users\Index`'s, `SalesRegions\Index`'s and `ProductCategories\Index`'s equivalents.

## Every server-derived property is `#[Locked]`, not just the ids

A Livewire public property is client-writable unless locked. The distinction is not "is it an id" but
"is this value ever legitimate request input". Form fields (`$name`, `$email`, `$roleId`, `$status`)
are input. Anything the server computed and only renders back is not.

✅ Good — `App\Livewire\Settings\Security` locks its derived list as well as its target id:

```php
// app/Livewire/Settings/Security.php
/**
 * @var array<int, array{id: int, name: string, authenticator: string|null, created_at_diff: string, last_used_at_diff: string|null}>
 */
#[Locked]
public array $passkeys = [];

#[Locked]
public ?int $deletingPasskeyId = null;
```

Leaving a derived array unlocked means a client can rewrite the rows the view will render: it turns
every unescaped output in that view into a self-injection sink, and it lets the confirmation copy on a
destructive modal disagree with the locked id the action will actually operate on.

**`App\Livewire\Users\Index::$users` was the last unlocked one, and task 0015 (finding F4) locked it.**
That screen's markup has shipped since task 0006, so the sink above was live rather than theoretical.
The lock has one cost worth knowing before writing a test against a list component: **`Livewire::test()->set('users', [])` now raises `CannotUpdateLockedPropertyException`**, and two existing
tests used exactly that — one incidentally (proving `usersSummary()` computes from its own query rather
than from the array) and one as its *only* mechanism (the empty-state branch, unreachable through a
sign-in journey because `loadUsers()` always finds the acting administrator's own row). Both were
rewritten rather than deleted or weakened: the first asserts `usersSummary()` against database state
`$users` could not have supplied, the second empties the result set through the `SoftDeletingScope` —
a state production can never reach (see
[soft-delete-patterns.md](../soft-delete-patterns.md)), which is what makes it a safe test-only mechanism.
**Rule: locking a derived property is a change to every test that wrote it — the coverage each one
carried must survive the rewrite, through a mechanism the application itself owns.**

> ⚠️ **Story 0025's `App\Livewire\ProductCategories\Index::$productCategories` is a deliberate, reasoned *exception* to this rule, not an oversight — and it is worth reading carefully next to the paragraph directly above it, because the two properties are no longer the parallel the component's own docblock says they are.** `$productCategories` is a plain `public array`, unlocked, holding the row shape `{id, name, productCount, canEdit, canDelete}`. Its docblock cites this exact page as recording "the identical rationale" for `App\Livewire\Users\Index::$users` — true of the *reasoning* (nothing in either component ever reads the array for a decision; every mutating method re-resolves its target with `findOrFail()` and re-authorizes against that fresh row, so a tampered row only misrenders the attacker's own screen), but **`$users` itself has been `#[Locked]` since task 0015** (the paragraph immediately above this one), so the two properties are not currently in the same state — one is the exception this rule allows for, the other is not an example of it any more. `$productCategories` is the correct thing to point at when this project next needs a citable "unlocked and safe because nothing reads it for a decision" instance; `$users` is not, until or unless a future story deliberately unlocks it again. The property's own docblock improves on `$users`'s original (pre-lock) precedent by stating the rationale **on the property itself** rather than only in this security doc — worth copying for any future property that stays deliberately unlocked.

> ✅ **Story 0027's `App\Livewire\Products\Editor` locks every imagery property it derives, and leaves `$regionIds` deliberately unlocked beside them — the same "is this ever legitimate request input" test, answered oppositely for two properties on the same screen.** `#[Locked] ?string $featuredMediaId`, `#[Locked] ?array $featuredPreview`, `#[Locked] array $galleryMediaIds` and `#[Locked] array $galleryPreviews` are all written only from a server-side `Media::find()`/`whereIn()` lookup inside `setFeaturedImage()`/`addGalleryImages()` (never from the `#[On]` event payload's own `title`/`url` fields — the "derive, never accept" rule this page already states for `WysiwygEditor::insertImage()`), so a tampered `updates` payload cannot inject an arbitrary title/URL pair into either preview or silently grow the gallery past `MAX_GALLERY_SIZE` through a locked-property write. `$regionIds`, by contrast, **is** the `SearchableMultiSelect` child's `#[Modelable]` binding target — exactly the same reason `Gallery`'s `$open` and `WysiwygEditor`'s `$showGallery` stay unlocked — and its safety comes from `save()`'s own mandatory `resolveSelected()` re-check (D-10) rather than from a lock that would break the binding outright.

> ✅ **Story 0022's `App\Livewire\Components\SearchableMultiSelect` is this rule's next real instance, and it broadens what "server-derived" means rather than merely repeating the pattern.** Phase 4 finding F-1 (Medium) locked `$disabled` for the same reason story 0021's `WysiwygEditor::$disabled` finding did — a tampered `updates` payload setting `disabled: false` directly would bypass every `if ($this->disabled)` guard in `selectOption()`/`removeOption()`/`updatedSearch()` — and F-3 (Medium) went further, locking `$minSearchLength`, `$debounceMs` and `$resultLimit` too, none of which is server-*computed* at all: they are values a **consumer sets once from its own Blade attribute** and never legitimately writes again. `$resultLimit` is the sharpest case — it drives `$fetchLimit` (`resultLimit + 1 + count($selected)`) inside `updatedSearch()`, so a client-supplied `resultLimit: 999999` would defeat the whole "a bounded fetch is what makes an 8,100-row resolver safe" contract D9 exists to guarantee, turning a display-tuning knob into a resource-exhaustion lever the moment it is left writable. `$optionResolver` (F-1's sibling finding) and `$maxChipAreaHeight`/`$unresolvableSelected`/`$selectedOptions`/`$results` complete the set — **seven** locked properties in total against two deliberately-unlocked ones (`$search`, the live search box, and `$selected`, the `#[Modelable]` binding D4 requires stay writable). The rule from `App\Livewire\Settings\Security` at the top of this section — "is this value ever legitimate request input", not "is it an id" — is what all seven answer identically to `no`, whether the value is computed by the server or merely *configured once* by a trusted caller and never meant to move again.

### Confirmed safe: `wire:click="$toggle('prop')"` is the same write channel as `wire:model`, and `#[Locked]` still binds it

Task 0018 replaced a bare `wire:model="active"` with `wire:click="$toggle('active')"` on the Sales
Regions edit modal's checkbox, for a real-browser automation reason unrelated to security (recorded in
that story's own task file). Because it *looks* like a method call, the question it raises is whether it
opens a second, ungated write path. **It does not** — and the reasoning is worth keeping so the next
screen that makes the same substitution does not re-derive it.

Verified against the installed vendor source, not inferred:

```js
// vendor/livewire/livewire/dist/livewire.esm.js
wireProperty("$toggle", (component) => (name, live = true) => {
  return component.$wire.set(name, !component.$wire.get(name), live);
});

wireProperty("$set", (component) => async (property, value, live = true) => {
  dataSet(component.reactive, property, value);
  if (live) {
    component.queueUpdate(property, value);      // <-- the `updates` payload, same as wire:model
    return fireAction(component, "$set");
  }
  return Promise.resolve();
});
```

Four properties follow, and each one is why this is a non-event:

- **`$toggle` is client-side sugar with no server counterpart.** It resolves to `$set`, whose only
  server-visible effect is `queueUpdate()` — an entry in the request's **`updates`** payload, the
  identical channel a `wire:model` write uses. There is no second pipeline.
- **The `$set` *call* is a no-op server-side.** `Livewire\Features\SupportMagicActions` lists `$set`
  in `$magicActions` and `$returnEarly()`s on it, so no method is dispatched and no new callable
  surface exists.
- **`#[Locked]` binds it**, because locking is enforced on the `updates` channel via
  `SupportLockedProperties\BaseLocked::update()`. Confirmed by execution rather than by reading:
  `Livewire::test(SalesRegions\Index::class)->set('regions', [])` and `->set('editingRegionId', 'forged')`
  both raise `CannotUpdateLockedPropertyException`.
- **The markup grants the client nothing it did not already have.** The property name is a literal in
  the compiled Blade, but an attacker never needed it — a hand-crafted `updates` payload can name any
  *unlocked* public property regardless of what the view binds. The markup is not the boundary;
  `#[Locked]` is.

**Rule: choosing between `wire:model`, `wire:model.live` and `wire:click="$toggle(...)"` is a
reactivity decision, never an authorization one.** All three land in the same `updates` payload, none
of them runs a `Gate` check, and none of them persists anything — the persisting method
(`save()` here) is where the ability is asked. The one behavioural difference worth knowing is
unrelated to security: `$toggle` defaults to `live = true`, so it forces a `/livewire/update` round
trip per click where a deferred `wire:model` would batch with the next action.

## A save-time gate and its display-only twin must share one resolution method, or the two will drift

`App\Livewire\Components\SearchableMultiSelect` ships **no** `Gate::authorize()` call of its own — by design (decision D7 in the task file, restated in the component's own docblock): the shell owns no table and no domain knowledge, so authorization belongs entirely to whatever `MultiSelectOptionsResolver` a consumer supplies. Nothing in this section's rules 1–3 apply to it directly. But story 0022's Phase 4 finding **F-2** (Medium) is the identical *shape* of failure one layer down, in a component whose "gate" is a validation check rather than a permission check — worth recording here because the drift it closes is the same one this whole page's `canEdit`/`canDelete` UI-hint pattern already guards against, just without a `Gate` anywhere in the picture.

The component exposes two methods that both answer "is this selection fully resolved": `refreshSelectedOptions()` (called on `mount()` and after every select/remove, to decide what to render — an unresolved id becomes a distinct "unavailable" chip) and `assertSelectionResolvable()` (the consumer's save-time gate, per decision D12 — a selection carrying any unresolvable id must refuse the entire save with a `ValidationException`, never persist a subset). **The first implementation gave each its own resolution logic**, and `assertSelectionResolvable()`'s caught only a thrown `UnresolvedSelectionException` — so a resolver that (wrongly) returns a *short array* instead of throwing, per D12's own total-function contract, passed the save-time gate silently while the display path already knew better. A consumer's own `MultiSelectOptionsResolver` implementation getting the contract wrong is exactly the failure D12 exists to make impossible; a gate that only *sometimes* catches it is worse than no gate, because it looks tested and green until the one implementation that gets it wrong ships.

The fix is the shape this page's ability-hint rule already generalizes: **one private method, `resolveIdsAllowingPartialFailure()`, is now the single place that decides "is this id set fully resolved" — both `refreshSelectedOptions()` and `assertSelectionResolvable()` call it and nothing else**, so the two can never independently drift about what counts as resolved. It folds a short-array return into the same `missingIds` result a thrown exception produces, regardless of which shape the resolver actually used — closing the gap for a misbehaving resolver on the save path, not only the display path.

**The rule, stated to generalize past this one component:** when a component exposes both a *display-time* check and a *save-time* (or otherwise consequential) gate that must answer the same yes/no question, the two must call one shared method — never two independently-maintained versions of "is this OK", however similar they look on the day both are written. This is the non-`Gate` sibling of [the `canEdit`/`canDelete` UI-hint rule](../../architecture/authorization/grant-meta-rules-and-ui-hints.md#gateallows-in-a-list-query-is-a-ui-hint-not-a-layer) elsewhere in this doc set: there, a rendered hint must mirror the exact `Gate` call it precedes or the two can disagree about what a click will do; here, there is no `Gate` at all, but a display flag and a refusal gate answering the same question are held to the identical discipline.
