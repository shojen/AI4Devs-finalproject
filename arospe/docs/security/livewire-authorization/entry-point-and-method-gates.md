# Livewire Component Authorization — The /livewire/update entry point and per-method gates

> Part of [Livewire Component Authorization](../livewire-authorization.md). **Read this part when:** you write a Livewire action/opener that mutates or discloses data and must decide where its `Gate` check goes. The other parts are listed in the [hub](../livewire-authorization.md#table-of-contents).

## `/livewire/update` is a second entry point, and only an allow-listed subset of route middleware follows the component there

Every Livewire action (`save()`, `deleteUser()`, …) is a `POST /livewire/update`, **not** a request to
the component's own route. Livewire re-applies route middleware only for the classes hardcoded in
`Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware::$persistentMiddleware`. The installed
list (`vendor/livewire/livewire/src/Mechanisms/PersistentMiddleware/PersistentMiddleware.php`) is:

```php
protected static $persistentMiddleware = [
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
    \Laravel\Jetstream\Http\Middleware\AuthenticateSession::class,
    \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
    \Illuminate\Routing\Middleware\SubstituteBindings::class,
    \App\Http\Middleware\RedirectIfAuthenticated::class,
    \Illuminate\Auth\Middleware\Authenticate::class,
    \Illuminate\Auth\Middleware\Authorize::class,
    \App\Http\Middleware\Authenticate::class,
];
```

Read it for what is **absent**, not only for what is present:

| Route middleware | Class | Follows the component to `/livewire/update`? |
| --- | --- | --- |
| `auth` | `Illuminate\Auth\Middleware\Authenticate` | ✅ yes |
| `can:<ability>` | `Illuminate\Auth\Middleware\Authorize` | ✅ yes |
| `permission:` / `role:` / `role_or_permission:` (Spatie) | `Spatie\Permission\Middleware\*` | ❌ **no** |
| `verified` | `Illuminate\Auth\Middleware\EnsureEmailIsVerified` | ❌ no |
| `password.confirm` | `Illuminate\Auth\Middleware\RequirePassword` | ❌ no |
| `throttle:` | `Illuminate\Routing\Middleware\ThrottleRequests` | ❌ no |

✅ Good — the real route, which gates with `can:` precisely because Spatie's middleware is not on that
list:

```php
// routes/users.php
Route::livewire('users', UsersIndex::class)
    ->middleware(['can:users.view'])
    ->name('users.index');
```

Spatie registers every permission as a Gate ability, so `can:users.view` is equivalent in meaning and
**is** re-applied on every action round-trip. `permission:users.view` would protect only the initial
`GET /users`.

❌ Bad — reads as equivalent, and leaves every `save()` / `deleteUser()` round-trip ungated at the
route layer:

```php
// anti-pattern — do not do this on a Livewire route
Route::livewire('users', UsersIndex::class)->middleware(['permission:users.view']);
```

Two corollaries that are easy to miss:

- **`throttle:` does not follow the component either.** Rate limiting a Livewire screen at the route
  is decorative; the limit has to live inside the action (as `App\Actions\Users\RequestEmailChange`
  does with `RateLimiter::attempt()`), or in a `Livewire::actionThrottle`-style guard inside the
  component. Do not assume a route-level `throttle:` covers a component method.
- **`password.confirm` does not follow the component either.** `settings/security` carries it at the
  route, so it protects the page load only — a hijacked session that already holds a rendered snapshot
  is not re-challenged per action.

### The worked example for the `password.confirm` row (task 0015a)

That row sat in the table above as a bare ❌ from task 0004 until task 0015a became the **first code in
this repo to act on it**. It is the clearest case in the table, because the naive fix is not merely
weaker than the shipped one — it is *inert* for the thing it would be added to protect.

❌ Bad — the shape a reader who has not internalised the allow-list will reach for (adapted to
illustrate; deliberately **not** present in `routes/users.php`, which task 0015a leaves unchanged):

```php
// anti-pattern on a Livewire route — guards the initial GET /users and nothing else.
// save() and deleteUser() run on /livewire/update, where RequirePassword never executes.
Route::livewire('users', UsersIndex::class)
    ->middleware(['can:users.view', 'password.confirm']);
```

Two failures at once, and the second is easy to miss: every privileged mutation stays unguarded, **and**
a name-only edit — which the step-up layer deliberately exempts — would now be blocked, because route
middleware cannot see which operation a round trip is about to perform.

✅ Good — the check moves into the method that performs the operation, reading the *same* session key
and timeout the middleware would have:

```php
// app/Actions/Users/UpdateUser.php — last statement of authorizeRoleAndStatusChange(),
// which __invoke() reaches only when ! $isSelfEdit
if (! $isNoOpRoleChange || $emailChanged || $statusChanged) {
    ($this->ensureRecentPasswordConfirmation)();
}
```

```php
// app/Livewire/Users/Index.php — deleteUser(), after Gate::authorize('delete', $target)
try {
    $ensureRecentPasswordConfirmation();
} catch (PasswordConfirmationRequiredException) { /* log, then redirect to re-confirm */ }
```

Three properties generalise to the next screen that needs step-up:

- **Route middleware being unavailable is what forces the guard into the method — not a preference.**
  The same reasoning that makes `can:` mandatory over `permission:` on this route makes an in-method
  check mandatory over `password.confirm` on it.
- **An in-method guard can be conditional; route middleware cannot.** This is a genuine gain rather
  than a workaround: the guard fires on a role, status or third-party email change and on nothing
  else, which route middleware could never express.
- **The guard runs after every `Gate::authorize()` on its branch**, so a permission refusal is never
  converted into a credential prompt. See [step-up-authentication.md](../step-up-authentication.md),
  which owns the full rule set.

⚠️ **`settings/security` is still the ❌ row's unfixed case.** It relies on route middleware alone, so
its own `/livewire/update` round trips are not re-challenged. Pre-existing, out of task 0015a's scope,
and named as a residual rather than left implicit.

## Gate at the top of every method that mutates or discloses

Because the route-level `can:` only proves the *page-level* ability (`users.view` here), each method
must still authorize its own, narrower ability, as its **first statement**, before it reads input or
touches the database:

```php
// app/Livewire/Users/Index.php
public function save(CreateUser $createUser, UpdateUser $updateUser, RequestEmailChange $requestEmailChange): void
{
    $target = null;

    if ($this->editingUserId === null) {
        Gate::authorize('create', User::class);
    } else {
        $target = User::findOrFail($this->editingUserId);
        Gate::authorize('update', $target);
    }

    $this->email = Str::lower($this->email);
    // ...
}
```

`mount()` authorizes too (`Gate::authorize('viewAny', User::class)`) — that is not redundant with the
route middleware, because `Livewire::test()` and any nested-component mount reach `mount()` without a
route.

The scope of the rule is **"mutates or discloses"**, not "mutates". A method such as
`openEditModal(string $userId)` writes no row, but it reads a target user and copies their attributes
into public component state — that is a disclosure step and it belongs behind the same ability the
subsequent write requires.

### The shipped disclosure gates, and why the disclosure check is the *stronger* ability

Task 0004's audit named that gap (finding **F7**); **task 0015 closed it.** Until then all three of
`App\Livewire\Users\Index`'s openers — `openCreateModal()`, `openEditModal()` and `confirmDelete()` —
performed **no** authorization at all, so any holder of `users.view` could call
`openEditModal($someId)` over `/livewire/update` and read back another user's `pending_email` and
`status` in the response snapshot. The three shipped gates:

```php
// app/Livewire/Users/Index.php
public function openCreateModal(): void
{
    Gate::authorize('create', User::class);
    // ...
}

public function openEditModal(string $userId): void
{
    $target = User::findOrFail($userId);

    if (! $target->is(Auth::user())) {
        Gate::authorize('updateSensitiveAttributes', $target);
    }
    // ... only now are $editingPendingEmail / $status / … assigned
}

public function confirmDelete(string $userId): void
{
    $target = User::findOrFail($userId);

    Gate::authorize('delete', $target);
    // ... only now are $deletingUserId / $deletingUserName assigned
}
```

Four properties, each of which is the answer to a question this shape reliably raises:

- **The edit opener asks a *stronger* ability than the write it precedes, and that is not an
  inversion.** `save()`'s edit branch authorizes plain `update`; the opener authorizes
  `updateSensitiveAttributes`. The asymmetry is correct because the two happen under different
  conditions: on the write path the stronger ability is asked by `UpdateUser` **conditionally** — only
  once a status or email change is actually detected (`$emailChanged || $statusChanged`) — whereas the
  opener discloses `pending_email` and `status` **unconditionally**, before the actor has decided
  anything. `UserPolicy::updateSensitiveAttributes()` is the ability named after exactly those two
  attributes, so the disclosure is gated on the ability that owns what is being disclosed. **Rule: a
  disclosure gate must cover every attribute the method copies out, not the operation the actor might
  go on to perform.**
- **The component performs no tier lookup to get there.** The obvious-looking alternative — "if the
  target holds `Administrator`, ask `updateSensitiveAttributes`, else ask `update`" — was rejected: it
  is the exact `administratorRoleId()` / `authorizeRoleChange()` shape task 0008a **deleted** from this
  same component (see [the section below](action-level-authorization.md#authorization-that-lives-only-in-the-component-is-bypassed-by-every-other-call-site-of-the-action)).
  No branch is needed, because the policy already contains it: `updateSensitiveAttributes()` delegates
  to `update()` first and then returns `true` outright for any target that does not hold the
  Administrator role. The unconditional call is therefore **identical to `update` for an ordinary
  target and strictly stronger for an Administrator-holding one** — one call site, zero role knowledge
  in the component.
- **The one branch that does exist is an identity check, never a role check.** `$target->is(Auth::user())`
  exempts the actor's own row, mirroring the identity exemption `UpdateUser`'s `$isSelfEdit` already
  applies at the write layer: `pending_email` and `status` are not a disclosure to the row's own owner,
  who already reads both at `settings/profile`. Accepted side effect, recorded rather than discovered
  later: an actor holding only `users.view` can open **their own** edit modal, which is no regression
  (the method had no check at all before) and grants no write — `save()` and `UpdateUser` still refuse
  any actual change.
- **`confirmDelete()` gets no such exemption, deliberately.** Its gate protects an *irreversible
  action*, not a disclosure, so "my own row" is not a reason to relax it. The consequence is that an
  actor whose own row holds `Administrator` is refused at `confirmDelete()` with an
  `AuthorizationException` — the same refusal `UserPolicy::delete()` produces for any other
  Administrator-holding target — rather than reaching `deleteUser()`'s self-delete no-op (see
  [architecture/authorization.md](../../architecture/authorization/grant-meta-rules-and-ui-hints.md#gateallows-in-a-list-query-is-a-ui-hint-not-a-layer)).
  Stricter, and accepted as such.

> ✅ **Confirmed on a second screen, story 0025.** `App\Livewire\ProductCategories\Index` gates its two
> openers (`openCreateModal()`, `openEditModal()`) and its delete-confirmation opener
> (`confirmDelete()`) exactly like `Users\Index` above, plus `save()` and `deleteProductCategory()` —
> every mutating **and** disclosing method, five in total, none skipped. It is a simpler instance than
> `Users\Index`'s, worth reading as the contrast: `ProductCategoryPolicy::update()`/`delete()` ignore
> their target entirely (no Administrator-tier branch, no self-row identity exemption — a product
> category has no owner and no privilege tier), so every opener asks the *same* ability `save()`/
> `deleteProductCategory()` would ask further down, with no `updateSensitiveAttributes`-style stronger
> variant to reach for. `openEditModal(string $categoryId)` and `confirmDelete(string $categoryId)`
> each resolve `ProductCategory::findOrFail($categoryId)` before authorizing, exactly matching
> `openEditModal()`'s `User::findOrFail($userId)` shape above — the target is read once, authorized
> against, and only then copied into public state. `App\Actions\Auth\LogRefusedPrivilegedAttempt::authorize()`
> is called with `targetType: 'product_category'`/`targetId: $target->id` passed explicitly at every
> site, since `resolveTarget()` auto-resolves only `User` and `Role` — a non-`User`/`Role` target that
> omitted these would fall back to the generic `[null, null]` pair and misattribute the refusal line.
> See [architecture/authorization.md](../../architecture/authorization/policies-sales-media-categories.md#productcategorypolicy--the-fifth-policy-and-the-first-to-gain-its-call-site-in-a-later-story-than-the-one-that-created-it).

### A screen owned by one module disclosing another module's records

Every disclosure gate above asks an ability from the **same** model/policy the screen already
belongs to. Story 0047's `App\Livewire\Customers\Show` is the first to disclose records that belong
to a **different** module entirely: its order-history section renders `order_number`, `status` and
`total` — columns `App\Policies\OrderPolicy` owns, not `CustomerPolicy`. The rule from above still
applies unmodified — *"a disclosure gate must cover every attribute the method copies out"* — it
just resolves to a different policy than the one gating the rest of the page:

```php
// app/Livewire/Customers/Show.php
public function mount(Customer $customer): void
{
    Gate::authorize('viewAny', Customer::class);   // gates the whole page

    $this->customerId = $customer->id;
}

#[Computed]
public function canViewOrderHistory(): bool
{
    return Gate::allows('viewAny', Order::class);  // gates ONLY the order-history section
}

#[Computed]
public function orders(): array
{
    if (! $this->canViewOrderHistory()) {
        return [];                                  // the guard IS the disclosure gate
    }
    // ...
}
```

Two things this instance adds that the same-module cases above never had to decide:

- **The refusal is an omission, not a 403.** Every gate earlier on this page throws
  `AuthorizationException` on failure — correct there, because the gated method is the *only* thing
  the actor came to do (open a modal, delete a row). Here the page's primary content (the identity
  header) is exactly what `customers.view` already grants, so refusing the whole request would deny
  access the actor legitimately holds. Worse, a 403 specifically for the order-history section would
  itself disclose that this customer has an order-history surface behind an ability the actor lacks —
  a smaller leak than the order rows themselves, but a leak. The section is instead **omitted
  entirely** — no heading, no empty-state message, no "insufficient permission" notice — so an actor
  without `orders.view` cannot tell a customer with fifty orders from one with none. `canViewOrderHistory()`
  is read by both the guard and the view's own `@if`, so the two can never independently drift.
- **No new policy, and no policy call site was even new.** `App\Policies\OrderPolicy::viewAny()`
  already existed (story 0045), shipped with no caller on purpose, its own docblock naming the
  eventual first caller as a deliberate hand-off rather than a gap. This story is that caller — the
  general rule this page states elsewhere, *[an authorization rule belongs to the action, not to one
  of its callers](../../conventions/directory-structure/controllers-and-authorization-rule.md#an-authorization-rule-belongs-to-the-action-not-to-one-of-its-callers)*,
  extends unchanged to a rule belonging to the **model whose records are being disclosed**, regardless
  of which module's screen happens to be the one asking.

Two consequences for tests, both of which task 0015 had to absorb: a test that used to prove a
mutating method refuses (`save()`, `deleteUser()`) can no longer reach it by calling the opener as an
under-privileged actor, because the opener now throws first — and it cannot skip the opener either,
since `#[Locked] $editingUserId` / `$deletingUserId` are writable only there. The shape that works,
already shipped twice in this repo, is to call the opener **while the actor still holds the
permission**, then revoke it and flush the permission cache, then call the mutating method
(`tests/Feature/Users/IndexTest.php`, `tests/Feature/Roles/IndexTest.php`). Second: an
exception-only assertion is not enough for a disclosure gate — assert the component's **state**
(`assertSet('editingPendingEmail', null)`), because a check placed *after* the assignments would pass
an exception-only test while having already disclosed the values.

### Gating a method is not the same as knowing when the gate fired

Everything above is about making a method **refuse**. Task 0015b added the half that was missing for two years of stories: a refusal that nobody records is correct and invisible, and on a component reached over `/livewire/update` that matters more than on an ordinary route. An actor probing `openEditModal($someId)` against one target id after another is doing so through an endpoint with **no server-rendered page load per attempt**, so there is not even an access-log line shaped like the attempt — the whole surface is `POST /livewire/update`, identical for a successful save and for the fiftieth refused probe.

**Rule: every method gated by the section above records its own refusal, through the one shared helper, before the exception propagates.**

✅ Good — the shipped call sites. `Gate::authorize()` is replaced by the helper's own throwing wrapper, which logs and then performs the identical check:

```php
// app/Livewire/Users/Index.php — openEditModal()
public function openEditModal(string $userId, LogRefusedPrivilegedAttempt $logRefusedPrivilegedAttempt): void
{
    $target = User::findOrFail($userId);

    if (! $target->is(Auth::user())) {
        $logRefusedPrivilegedAttempt->authorize('updateSensitiveAttributes', $target);
    }
    // ...
}
```

❌ Bad — the shape every one of these sites had until task 0015b, and the one a new method will be written with unless the rule is stated (adapted from the pre-0015b code, which is otherwise identical):

```php
// anti-pattern — refuses correctly, records nothing
Gate::authorize('updateSensitiveAttributes', $target);
```

Three things specific to a Livewire component, each of which a reader hits within minutes of copying this:

- **The helper is a method-injected parameter, not a constructor dependency.** These are Livewire action methods, called only through `wire:click` and `Livewire::test()->call()`, so they follow this repo's ordinary per-method action-injection convention — and adding the parameter changes nothing at either call site, because Livewire resolves an unmatched typed parameter from the container after the client-supplied arguments. The **actions** behind the screen constructor-inject the same class instead, for the documented reason that their `__invoke()` signature is a public contract; see [conventions/code-style.md](../../conventions/code-style.md#exception-an-actions-own-dependency-is-constructor-injected-when-the-method-signature-is-a-public-contract).
- **`mount()` is the one deliberate exception, and the reason is the allow-list table at the top of this page.** `UserPolicy::viewAny()` / `RolePolicy::viewAny()` check the *identical* abilities the routes' `can:users.view` / `can:roles.manage` enforce, and `can:` — unlike `permission:` — **is** on `PersistentMiddleware`, so it re-applies on `/livewire/update` too. An actor who would fail `mount()` is refused by the route on every real request and never reaches the component; a log there could only ever fire from a `Livewire::test()` mount. The check stays as defence in depth, unlogged, with the tripwire recorded in both docblocks: *if `viewAny()` ever gains a condition the route's own `can:` ability does not check, this refusal becomes reachable over HTTP and must be logged.* Note the asymmetry with the `password.confirm` row directly above — that middleware's **absence** from the allow-list is what forced a guard into the method; `can:`'s **presence** is what makes one unnecessary here. Same table, opposite conclusions, and reading it in only one direction gets one of the two wrong.
- **A disclosure gate's test needs the same state assertion it always did, plus the log.** An exception-only assertion cannot tell a gate that ran before the assignments from one that ran after; adding `Log::spy()` does not change that. `tests/Feature/Users/RefusalLoggingTest.php` asserts the throw, the recorded context array, **and** that `editingPendingEmail` / `status` stayed unpopulated.

What the line actually contains, why its keys are generic, the two message strings a defender must filter for, and the log-ceiling rule for a rate-limit site shared with an unprivileged caller are owned by [architecture/authorization.md](../../architecture/authorization/step-up-and-refusal-logging.md#recording-a-refusal--what-every-gate-owes-the-audit-trail) — not repeated here.

> **`App\Livewire\Media\Gallery::mount()` is the exception's own exception, and it logs.** The middle bullet above excludes `mount()` because the route's `can:` refuses first. That component has no route (story 0020), so nothing refuses first and `mount()` is the only gate a real caller reaches — which is precisely the refusal the recipe exists to record. Same table, third conclusion.

### The routeless case: a component with no route has no per-request backstop at all

Story 0020's Phase 4 **F-1** (Medium). This is the sharpest real case this page's own *"gate every method that mutates or discloses"* rule has produced, because it is the one component where that rule is not one layer of two — it is the only layer there is.

**The mechanism is the allow-list table at the top of this page, read for a component that has no middleware of its own.** Livewire's `PersistentMiddleware` re-applies a component's **route's** middleware on every `/livewire/update` round trip, which is why `can:users.view` keeps refusing a revoked actor on `App\Livewire\Users\Index` regardless of what the component does. `App\Livewire\Media\Gallery` is an **embedded child** — a modal Products and Blog mount inside their own screens — so there is no route, and therefore no `can:media.view` to replay. What Livewire replays is the **host page's** middleware, which says nothing about `media.*`.

The consequence is exact and easy to miss in review: **`mount()` runs once, on the initial render. Every subsequent call arrives with no authorization behind it whatsoever.**

❌ Bad — the shipped first implementation. It gates `mount()`, which is what the three routed screens in this repo do, and it reads correct:

```php
// as found by Phase 4 round 1 — do not copy onto a routeless component
public function mount(LogRefusedPrivilegedAttempt $log): void
{
    $log->authorize('viewAny', Media::class);
}

#[Computed]
public function tiles(): array { /* returns the whole library */ }

public function toggleSelect(string $id): void { /* … */ }
public function confirmSelection(): void      { /* … */ }
```

An actor opens the gallery legitimately, has `media.view` revoked mid-session, and keeps browsing, searching and selecting the entire media library — over `POST /livewire/update`, indistinguishable in an access log from ordinary use — for as long as the page stays open.

✅ Good — the shipped fix. Each of the three re-checks, and the render-path one does it differently on purpose:

```php
// app/Livewire/Media/Gallery.php
#[Computed]
public function tiles(): array
{
    if (Gate::denies('viewAny', Media::class)) {
        return [];                                  // fails CLOSED, never throws
    }
    // …
}

public function toggleSelect(string $id, LogRefusedPrivilegedAttempt $log): void
{
    $log->authorize('viewAny', Media::class);       // throws, and records
    // …
}
```

Three rules, and the third is the one a reviewer is most likely to get backwards:

1. **Enumerate the *methods*, not the abilities.** "Is this screen gated" is the wrong question for a component with no route; "can this method be called on its own over `/livewire/update`" is the right one, and for any public method on a mounted component the answer is yes. The two ungated methods here — `cancel()` and `cancelEditing()` — are ungated only because each writes nothing but the component's own form state.
2. **A `#[Computed]` property is a method.** `tiles()` reads like data and is a full entry point: the whole library, re-queried each request, filtered by whatever `$search` the client last set. Nothing about the `#[Computed]` attribute makes it internal.
3. **A render-path gate fails closed; an action-path gate throws — do not unify them.** `tiles()` is reached from `render()`, so an `AuthorizationException` there propagates out of the *child* and takes down the **host** page — turning a revoked media permission into a 500 on the product editor, which is the exact failure the consumer's `@can('viewAny', \App\Models\Media::class)` wrapper exists to prevent, arriving one layer lower. A throwing `tiles()` and a silently-empty `confirmSelection()` are both wrong: the first breaks an unrelated screen, the second hands the consumer an empty selection with no refusal recorded anywhere.

Two further properties, both already rules on this page and both worth re-reading in this shape:

- **The `@can` wrapper at the consumer site is a layer, never the gate.** It decides whether the child is rendered into the host page at all — reachable only during the host's own render, and not from `/livewire/update`. It is what stops a `media.view`-less actor from 403-ing a screen they are otherwise entitled to; it protects nothing.
- **A disclosure gate here is legitimately *stronger* than its neighbours.** `startEditing()` asks `update` (`media.edit`) where `toggleSelect()` beside it asks `viewAny` (`media.view`), because its effect is opening a **write form**, not disclosing a value the tile already renders — [the same rule](#the-shipped-disclosure-gates-and-why-the-disclosure-check-is-the-stronger-ability) `Users\Index::openEditModal()` follows. Phase 5's finding F-8 records this on the method's own docblock specifically so a reviewer does not "fix" the pair into a matching `viewAny`/`viewAny` and weaken the one gate that needs to be strong.

Where this pattern sits relative to the module gate, the sidebar registry and the refusal recipe is [architecture/authorization.md](../../architecture/authorization/policies-variants-and-routeless.md#a-routeless-livewire-component-has-no-per-request-authorization-backstop)'s to own; the mechanism above is this page's.

> ✅ **Story 0027's `App\Livewire\Products\Editor` is a *routed* component, and it still needed the identical fix — because rule 1 ("enumerate the methods, not the abilities") does not stop applying just because a route exists.** `Editor::setFeaturedImage()` and `Editor::addGalleryImages()` are `#[On('featured-image-selected')]`/`#[On('product-images-added')]` listeners — page-globally registered, client-dispatchable, exactly like any other public Livewire method — on a component whose route **is** gated (`can:products.view`, replayed on every `/livewire/update` round trip via `PersistentMiddleware`). That replay is real, and it is not the whole story: it re-checks `products.view`, the ability the route names, and says nothing about `media.view` — the ability these two listeners actually need, since each reads a `Media` row's title/URLs into the component. An actor holding `products.edit` but lacking `media.view` passes the route's middleware on every request and could still dispatch either event directly, bypassing the `@can('viewAny', \App\Models\Media::class)` wrapper that only hides the picker *button* — the same "hiding a control is never the control" property [`Gallery`'s own consumer contract](../../api/products/routeless-components.md#applivewiremediagallery--a-gated-surface-with-no-route-and-how-a-consumer-embeds-it) already states. Code review's own re-audit (F-6) found both listeners ungated in the first implementation and fixed them the identical way: `Gate::authorize('viewAny', Media::class)` via `LogRefusedPrivilegedAttempt` as each method's own first statement. **The generalised rule: a route's `can:` replay only covers the ability it names — a method inside that route asking a *different, finer* ability still needs its own gate, whether or not the component has a route at all.** The two listeners also re-derive the selected `Media` row from `Media::query()->find($id)`/`whereIn('id', $ids)` rather than trusting the event payload's own `title`/`url` fields, the same discipline `WysiwygEditor::insertImage()` already established.

> ✅ **Story 0021's `App\Livewire\Components\WysiwygEditor` is this section's second real instance, and rule 1 held it to account exactly as designed.** It is itself routeless, and it embeds `Gallery` the same way a host screen does — so it is simultaneously a `Gallery` consumer and a second routeless surface of its own. Its first implementation gated neither `openGallery()` nor `insertImage()`, the same ❌ shape this section documents; Phase 4 finding F-2 fixed both as an ordinary application of rule 1 (`Gate::authorize('viewAny', Media::class)` via `LogRefusedPrivilegedAttempt` as each method's own first statement), and `insertImage()` additionally re-derives the selected `Media` row from the database rather than trusting the client-supplied `url`/`title` fields — the same "derive the state, never accept it" discipline [model-instance-trust.md](../model-instance-trust.md) already states for a caller-supplied instance, applied here to a caller-supplied array instead. Neither method is a `#[Computed]` property, so rule 3's fails-closed/throws split does not apply to either — both are ordinary user-triggered actions and both throw and log, matching `toggleSelect()`/`confirmSelection()` rather than `tiles()`.
