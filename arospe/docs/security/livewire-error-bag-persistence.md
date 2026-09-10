# Livewire error-bag persistence

Durable rules about **how long a `ValidationException` message lives inside a Livewire component**, and what declaring a property to keep one alive obliges you to do next.

Established by the Phase 4 re-audit of story 0036 (shipping rate rules), which found that the remediation for one finding created the condition for the next. Both halves below are verified by execution against this repo, not read off the framework source.

## The mechanism, stated once

Livewire persists the error bag across round trips in `SupportValidation::dehydrate()`, and filters it through `Livewire\Utils::hasProperty()` on the way out. **An error keyed on a name the component does not declare as a real public property renders exactly once — on the request that threw it — and is silently dropped on the next round trip.**

That single fact produces two opposite failure modes, and closing one opens the other unless both are closed together.

## Failure mode 1 — the message vanishes

An action throws `ValidationException::withMessages(['shippingZoneId' => …])`, the view binds `@error('shippingZoneId')`, and the component never declares `$shippingZoneId`. The callout renders on the throwing request and then disappears, leaving a still-open modal with no explanation of why nothing happened.

✅ The fix, as shipped in [`app/Livewire/Shipping/Zones.php`](../../app/Livewire/Shipping/Zones.php): declare the property, `#[Locked]` so it is never client-writable, and say in its docblock that it exists only to give the error bag something to survive against.

```php
// app/Livewire/Shipping/Zones.php
#[Locked]
public ?string $shippingZoneId = null;
```

`#[Locked]` here is not decoration. The property exists purely as a persistence anchor, so nothing should ever write it — verified by execution: `Livewire::test(Zones::class)->set('shippingZoneId', 'forged')` throws `CannotUpdateLockedPropertyException`.

`App\Livewire\ProductCategories\Index` is the same shape **without** the fix: it has no `productCategoryId` property, so its own `categories.delete_blocked` message is still dropped after one round trip. Recorded here as the live example of failure mode 1, not as a pattern to copy.

## Failure mode 2 — the message outlives its subject

Making the message persist is only half the job, and the missing half is not obvious, because it only becomes reachable *once the first half is fixed*.

A blocked delete on zone A leaves `shippingZoneId => "This zone is used by 3 shipping rates and cannot be deleted."` in the bag. Opening the delete-confirmation modal for a **different** zone B then renders zone B's name beside zone A's refusal — a factually false, alarming statement about a zone that has no rates at all and is perfectly deletable.

> **Corrected 2026-09-10, same Phase 4 pass — this was found and fixed within the same re-audit round that discovered it (finding R-1).** At the time this page was first written, `confirmDelete()` carried no reset and this failure mode was live; it is now closed, verified by execution (reverting the fix line reproduces the leak; restoring it closes it — see the two-target regression test in `tests/Feature/Shipping/ZonesTest.php`). Kept below as the ❌/✅ pair for the shape itself, per this project's audit-authored-page convention — the ❌ block is history, not the current file.

❌ The shape that produced it — an opener that set its target state without clearing the bag:

```php
public function confirmDelete(string $zoneId, LogRefusedPrivilegedAttempt $log): void
{
    $target = ShippingZone::findOrFail($zoneId);
    $log->authorize('delete', $target, targetType: 'shipping_zone', targetId: $target->id);

    $this->deletingZoneId = $target->id;
    $this->deletingZoneName = $target->name;
    $this->showDeleteModal = true;
    // nothing resets the persisted 'shippingZoneId' error
}
```

✅ The shipped shape — the same `resetValidation()` call the *other* two openers on that very class already carry (added by story 0034's own Phase 5 finding H-1, for the identical reason on the create/edit modal), now also on `confirmDelete()`:

```php
    $this->showDeleteModal = true;
    $this->resetErrorBag('shippingZoneId');
```

`closeDeleteModal()` already resets it, so the cancel path was never affected — which is exactly why the gap is easy to miss: every path a reviewer walks by hand is clean, and only reaching a second target *without* cancelling first exposes it.

## The rule

**Declaring a property so a validation error persists creates an obligation to clear that error at every method that re-points the component at a different target.** Persistence and reset are one change, not two — a component that gained the first without the second has traded "the message disappears" for "the message lies", and the second is worse, because a guard that reports a false positive teaches an administrator to distrust a guard that is otherwise correct.

Concretely, when adding a persistence-anchor property:

1. Declare it `#[Locked]`, and say in the docblock that it is never written to directly.
2. Add `resetErrorBag('<key>')` (or `resetValidation()`) to **every** opener — `openCreateModal()`, `openEditModal()`, `confirmDelete()` — not only to the closers. Openers are what re-point the component at a new target; closers only happen to be the path most people test.
3. Write the regression test as *two* component calls against *two different rows*, never one call against one row. A single-target test passes identically with and without the reset, so it proves persistence and nothing about scoping.

## How to tell the two modes apart in a test

They look the same from a single-target test and are trivially separable from a two-target one:

```php
$c = Livewire::test(Zones::class)
    ->call('confirmDelete', $blockedZone->id)
    ->call('deleteZone')
    ->assertHasErrors('shippingZoneId');   // mode 1 closed: it survived the round trip

$c->call('confirmDelete', $innocentZone->id)
    ->assertHasNoErrors('shippingZoneId'); // mode 2 closed: it did not follow us here
```

The second assertion is the one nothing else in this codebase currently makes, and the one that distinguishes a persisted message from a leaked one.
