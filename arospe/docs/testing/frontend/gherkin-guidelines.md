# Gherkin Scenario Guidelines

How to write BDD scenarios (Feature + Scenarios) for this app. In this repo a `.feature` file is a **specification artifact**, not something a Cucumber/`playwright-bdd` engine runs — see the [tooling decision](README.md#tooling-decision-read-this-first). A well-written scenario is valuable precisely because a human or LLM translates it faithfully into a Pest browser test; a sloppy scenario translates into a sloppy test.

Every rule below is grounded in this app's **real, existing flows** (login, registration, two-factor authentication, passkeys, profile/appearance/security settings — see [../../architecture/authentication.md](../../architecture/authentication.md) and [../../api/routes.md](../../api/routes.md)). There is no product/order/checkout domain in the code yet, so no example invents one.

## Table of Contents

- [The seven rules](#the-seven-rules)
  1. [Imperative vs. declarative scenarios](#1-imperative-vs-declarative-scenarios)
  2. [No overly technical details](#2-no-overly-technical-details)
  3. [Single When per scenario](#3-single-when-per-scenario)
  4. [Scenario Outline vs. duplicated scenarios](#4-scenario-outline-vs-duplicated-scenarios)
  5. [Consistent language / shared glossary](#5-consistent-language--shared-domain-glossary)
  6. [No ghost scenarios](#6-no-ghost-scenarios)
  7. [No loss of ubiquitous language](#7-no-loss-of-ubiquitous-language)
- [Scenarios whose actor is not a person](#scenarios-whose-actor-is-not-a-person-scheduled-and-system-triggered)
- [Domain glossary](#domain-glossary)
- [Given/When/Then → Pest it() translation convention](#givenwhenthen--pest-it-translation-convention)

## The seven rules

### 1. Imperative vs. declarative scenarios

Write scenarios in **business language** describing intent and outcome, not the mechanical UI steps to get there. Declarative scenarios survive UI redesigns; imperative ones break when a button moves.

❌ Imperative — narrates clicks and fields:

```gherkin
Scenario: Log in
  Given I open the "/login" page
  When I type "ada@example.com" into the email field
  And I type "password" into the password field
  And I click the "Log in" button
  Then I see the "/dashboard" page
```

✅ Declarative — states intent and observable outcome:

```gherkin
Scenario: A registered user signs in with valid credentials
  Given a registered user
  When the user signs in with valid credentials
  Then the user reaches their dashboard
```

### 2. No overly technical details

Keep DOM IDs, CSS classes, JSON payloads, HTTP status codes, and database column names out of scenarios. Those are implementation details that belong in the Pest translation, not the specification.

❌ Technical leakage:

```gherkin
Scenario: Reject bad login
  When the user POSTs to /login.store with an invalid password
  Then the response has a session error on the "email" key
  And "two_factor_confirmed_at" stays NULL
```

✅ Business-level:

```gherkin
Scenario: Sign-in is refused with a wrong password
  Given a registered user
  When the user tries to sign in with an incorrect password
  Then the user is told the credentials are invalid
  And the user remains signed out
```

(The mapping "session error on email" → `assertSee('...')` / "remains signed out" → `assertGuest()` happens in the [translation](#givenwhenthen--pest-it-translation-convention), invisibly to the scenario.)

### 3. Single When per scenario

Each scenario tests **one** action. If you need two `When` steps, you almost certainly have two scenarios, or the first `When` is really a `Given` precondition.

❌ Two actions in one scenario:

```gherkin
Scenario: Enable and then confirm two-factor authentication
  Given a signed-in user on the security settings page
  When the user enables two-factor authentication
  And the user confirms it with a valid authentication code
  Then two-factor authentication is active
```

✅ Split — the first action becomes the precondition of the second:

```gherkin
Scenario: User confirms a freshly enabled two-factor setup
  Given a signed-in user who has just enabled two-factor authentication
  When the user confirms it with a valid authentication code
  Then two-factor authentication is active on the account
```

### 4. Scenario Outline vs. duplicated scenarios

When several cases share the same structure and differ only by input, use a `Scenario Outline` with `Examples`. Don't copy-paste a scenario per input, and don't over-specify each case.

❌ Duplicated, over-specified:

```gherkin
Scenario: Registration fails with empty name
  Given a visitor on the registration page
  When the visitor registers with a blank name
  Then registration is refused

Scenario: Registration fails with invalid email
  Given a visitor on the registration page
  When the visitor registers with a malformed email address
  Then registration is refused

Scenario: Registration fails with a weak password
  Given a visitor on the registration page
  When the visitor registers with a password that is too weak
  Then registration is refused
```

✅ One outline over the shared structure:

```gherkin
Scenario Outline: Registration is refused with invalid details
  Given a visitor on the registration page
  When the visitor registers with <invalid_detail>
  Then registration is refused and the reason is shown

  Examples:
    | invalid_detail                    |
    | a blank name                      |
    | a malformed email address         |
    | a password that is too weak       |
    | an email that is already taken    |
```

### 5. Consistent language / shared domain glossary

Use the same term for the same concept across every feature file. If one scenario says "sign in" and another says "log in" for the same action, or "passkey" vs. "security key" for the same thing, readers and translators lose the thread. Pick the canonical term from the [domain glossary](#domain-glossary) and stick to it.

❌ Inconsistent within the same suite:

```gherkin
# feature A
When the member logs into the platform
# feature B
When the user signs in to their account
```

✅ One canonical verb everywhere (glossary says "sign in"):

```gherkin
# feature A
When the user signs in
# feature B
When the user signs in
```

### 6. No ghost scenarios

Every precondition and scenario must trace back to a **real user story or business conversation**. Never invent a precondition for narrative convenience or to make a scenario "feel complete". If the user story doesn't mention a state, don't assume it exists.

❌ Ghost precondition — nothing in the login story establishes a trial/subscription concept, and this app has no such thing:

```gherkin
Scenario: Sign in with an active subscription
  Given a registered user with an active premium subscription
  When the user signs in with valid credentials
  Then the user reaches their premium dashboard
```

✅ Grounded only in what exists (a `User`, credentials, the dashboard):

```gherkin
Scenario: A registered user signs in with valid credentials
  Given a registered user
  When the user signs in with valid credentials
  Then the user reaches their dashboard
```

If a scenario seems to need a concept the code doesn't have, that's a signal to ask the product owner — not to invent it. Mark it `TODO` with a concrete question, per the [Uncertainty Handling Rule](../../contracts.md).

### 7. No loss of ubiquitous language

Don't replace an established domain term with a generic synonym. This app's users and code speak of **passkeys**, **recovery codes**, and **two-factor authentication**; flattening those into "login token", "backup password", or "extra security step" erodes the shared vocabulary and makes scenarios ambiguous.

❌ Generic synonyms that blur meaning:

```gherkin
Scenario: Remove a login token
  Given a user who has a saved login token
  When the user deletes that token
  Then the token can no longer be used to log in
```

✅ Uses the real domain terms:

```gherkin
Scenario: Remove a passkey
  Given a signed-in user who has a registered passkey
  When the user removes that passkey
  Then the passkey can no longer be used to sign in
```

## Scenarios whose actor is not a person (scheduled and system-triggered)

Rule 1 requires a named business-role actor and forbids a bare `I`. It had no precedent for a scenario whose acting subject is not a person — a scheduled sweep, an automatic cancellation — and story 0064 (a scheduled command that publishes due posts) set the convention every later scheduled or queued feature inherits:

- **The `When` names the acting subject in business language** — *"the publication scheduler runs"*. Not `Given I`, and not *"the system"*: "system" is exactly the technical leakage rule 2 warns against and says nothing about what the process is for.
- **The `Given` still carries a named business-role actor wherever a human decision created the state** — *"a post scheduled by a blog editor for a time that has now arrived"*. This keeps rule 1's intent (business framing, real domain roles), not only its letter.
- **Never attribute the automatic transition to the human who did not perform it.** *"When the blog editor's scheduled time arrives"* reads as though a person acted, which is the specific confusion this kind of feature causes when it goes wrong.
- **Rule 3 is unaffected**: one `When` per scenario. Idempotency is its own scenario with its own `When` — *"a post the publication scheduler has already published … when the publication scheduler runs again"*.

## Domain glossary

Canonical terms for what exists in the code **today**, derived from [`app/Models/User.php`](../../../app/Models/User.php), [../../database/schema.md](../../database/schema.md), and [../../architecture/authentication.md](../../architecture/authentication.md). Use these exact terms in scenarios; don't substitute synonyms.

| Term | Definition | Where it lives |
| --- | --- | --- |
| **User** | A registered account holder. The only domain model in the app today. | `App\Models\User`, `users` table |
| **Visitor / guest** | An unauthenticated person (not signed in). | route `auth` middleware, `assertGuest()` |
| **Sign in** | Authenticate with email + password (canonical verb — not "log in", "sign on"). | `login` / `login.store` routes |
| **Sign out** | End the authenticated session. | `logout` route, `App\Livewire\Actions\Logout` |
| **Register** | Create a new user account. | `register` / `register.store` routes |
| **Email verification** | Confirming ownership of the account email via a signed link. | `verification.*` routes, `email_verified_at` |
| **Two-factor authentication (2FA)** | A second sign-in factor via a time-based authentication code. | `two_factor_*` columns, `Security` component |
| **Authentication code** | The 6-digit time-based code entered to confirm/challenge 2FA (not "OTP", "PIN"). | 2FA challenge flow |
| **Recovery code** | A single-use backup code to sign in when the authenticator is unavailable. | `two_factor_recovery_codes`, `RecoveryCodes` component |
| **Passkey** | A WebAuthn credential for passwordless sign-in (not "security key", "login token"). | `passkeys` table, `PasskeyAuthenticatable` |
| **Password confirmation** | Re-entering the current password to re-authorize a sensitive action. | `password.confirm` middleware on `security.edit`; the in-method step-up guard on the Users screen |
| **Step-up authentication** | Requiring a *recently* confirmed password before a privileged action, even though the actor already holds the permission (not "re-login", "2FA"). | `App\Actions\Auth\EnsureRecentPasswordConfirmation`, `auth.password_confirmed_at` |
| **Session** | A server-side authenticated session record. | `sessions` table (`SESSION_DRIVER=database`) |
| **Dashboard** | The authenticated landing page after sign-in. | `dashboard` route |
| **Security settings** | The page to manage password, 2FA, and passkeys. | `security.edit` route, `Security` component |

### Blog vocabulary

**Ratified by story 0063 (its D-22).** Stories 0058 to 0061 adopted these two terms **verbatim from the PRD's own Epic 4 scenarios** rather than coining any, and story 0063, whose three screens needed the whole Blog vocabulary at once, made them canonical. The Spanish UI copy renders a post as *artículo* (the PRD's caption reads "Nuevo artículo"); that is a translation choice living in `lang/es/blog-posts.php`, **not** a second domain term, so an English scenario never says "article". If the product owner disagrees, change the terms here and, since it is contested, record an ADR in `docs/decisions/`.

| Term | Meaning |
| --- | --- |
| **post** | A single blog entry (the PRD's word — not "article"). |
| **blog editor** | The actor who manages the blog, as the PRD's Epic 4 scenarios name them. |
| **publication scheduler** | The automated process that publishes a scheduled post once its time arrives; the acting subject of a `When`, never a person (story 0064; see [Scenarios whose actor is not a person](#scenarios-whose-actor-is-not-a-person-scheduled-and-system-triggered)). |

### Internationalization vocabulary

**Epic 5 has two deliberately separate locale concepts, and its own PRD text warns against conflating them** ([epic-5-internationalization.md](../../PRD/sections/epic-5-internationalization.md)). Story 0066 (Layer 1's backend) is the first to need either term in a scenario, so it fixes both here rather than leaving them to be coined ad hoc by a later story's Gherkin.

| Term | Meaning | Where it lives |
| --- | --- | --- |
| **admin UI language** | Layer 1 — the language an individual administrator's account renders the dashboard chrome and its own notification emails in (`en`/`es`). A per-account preference on `users.ui_locale`, falling back to the store-wide `App\Models\LocaleSetting::defaultUiLocale()` / `defaultNotificationLocale()` for an account that never chose. | `users.ui_locale`, `App\Http\Middleware\SetUiLocale`, `App\Models\User::preferredLocale()` (story 0066) |
| **store language** | Layer 2 — a language the store *offers its own content in* (e.g. a product description translated into a second language), catalogued in an admin-managed list. Has no per-account concept and no relationship to which language an administrator sees their own dashboard in. | `store_languages` table, `App\Models\StoreLanguage` (story 0068) |

A scenario about a signed-in administrator's own interface or emails uses **admin UI language**; a scenario about content the store presents to its customers in more than one language uses **store language**. Never use one term when the other is meant, even though both ultimately resolve to the same `en`/`es` value set today.

### Dashboard vocabulary

**Introduced by story 0082** (the dashboard home overview's read-side actions), extended by story 0083 (the dashboard UI). Its Gherkin names the actors by the business role that holds the relevant ability, and fixes three measure names. This subsection only registers them; it does **not** settle the open "order" vs. "sale" question in the TODO below — the measure **Sales** is a defined sum, not the canonical word for a purchase.

| Term | Meaning | Where it lives |
| --- | --- | --- |
| **catalog manager** | The actor who may view products (and so sees the low-stock widget). | `products.view` |
| **order manager** | The actor who may view orders (and so sees the latest-orders widget and the sales overview). | `orders.view` |
| **user manager** | The actor who may view users and, by that alone, sees only the users counter on the dashboard. | `users.view` |
| **administrator** | An actor holding every module's view ability but not the Super Admin role; sees the hero counters and all three widgets. | the seeded `Administrator` role |
| **super administrator** | The actor for whom every ability check passes through the Super Admin bypass; sees everything regardless of the permission rows. | `Gate::before` ([Super Admin](../../architecture/authorization/super-admin.md)) |
| **staff member with no module access** | A signed-in actor who holds no view ability; still reaches the dashboard (it is ungated) and sees the greeting and an empty-state message instead of counters or widgets. | no `*.view` ability |
| **staff member who may only view the media library** | An actor whose only ability is `media.view`; sees the images counter and no widget. | `media.view` |
| **hero** | The top band of the dashboard: the time-of-day greeting with the actor's first name, a tagline and the counters the actor may see. | `Overview` view |
| **widget** | One read-only card on the dashboard showing a module's latest data (blog posts, low stock, latest orders), shown only to an actor who may view that module, with a "view all" link. | `app/Livewire/Dashboard/` |
| **Sales** | The gross total sold in a period, over the selected order statuses (tax and shipping included, refunds not netted). | [Dashboard definitions](../../api/dashboard.md#sales-real-income-and-orders--definitions) |
| **Real income** | Money actually collected: paid or partially refunded, not cancelled, net of refunds. | same |
| **order administrator** | The actor who may edit orders (and so may mark one as paid); one who may also refund is named as such ("an order administrator who may refund"). | `orders.edit` (story 0084) |
| **payment state** | The order's money dimension, independent of its fulfilment status. Values: **Pending payment**, **Paid**, **Partially refunded**, **Refunded**. Scenarios use these labels, never `payment_status` or its stored values. | `payment_status` |
| **Orders** | The count of orders per period and status. | same |

### TODO — blog / ecommerce vocabulary (undefined)

The blog domain is built (`BlogPost`, `BlogCategory`, `BlogTag` in `app/Models/`) and its vocabulary is settled in [Blog vocabulary](#blog-vocabulary) above; the ecommerce domain is built too (products, orders, customers), and what this file still leaves unanswered is the purchase vocabulary in (b) and (c) below. Do **not** invent terms for what is undecided. This section still needs canonical terms decided by the product owner:

> `TODO (product owner): define the canonical vocabulary for the commerce domain. Concretely: (a) *[answered — "post", see "Blog vocabulary" above]* (b) for a purchase, what is the canonical term for the whole purchase ("order" vs. "sale") and for a single purchased item within it ("order line" vs. "line item" vs. "order item")? (c) is a buyer a "customer", "client", or "user"? Record the answers as a new row set here and, if the choice is contested, as an ADR in docs/decisions/.`

## Given/When/Then → Pest it() translation convention

Because no BDD engine runs the `.feature` files, each scenario is translated **by hand** into a Pest browser test in `tests/Browser/`. Map the three Gherkin sections onto the three sections of an `it()` body:

| Gherkin | Pest `it()` body section | Typical calls |
| --- | --- | --- |
| **Given** (preconditions) | Arrange | `User::factory()->create()` / `->withTwoFactor()`, `$this->actingAs(...)`, `Notification::fake()`, seeding a passkey |
| **When** (the single action) | Act | `visit('/login')`, `->fill(...)`, `->click('Log in')` — the one user action the scenario names |
| **Then** (outcomes) | Assert | `->assertSee(...)`, `->assertNoJavaScriptErrors()`, `assertGuest()` / `assertAuthenticated()`, DB/state assertions |

Conventions for the translation:

- **Name the `it()` after the scenario**, in behavior-and-condition form: `Scenario: Sign-in is refused with a wrong password` → `it('refuses sign-in with a wrong password')`. Never `it('login test')`.
- **One scenario → one `it()`.** A `Scenario Outline` with `Examples` → one `it()` driven by a Pest **dataset** (`->with([...])`), one dataset row per `Examples` row.
- **Always include `->assertNoJavaScriptErrors()`** in the Assert section (see [test-quality-checklist.md](test-quality-checklist.md)).
- **Set up state via Laravel helpers, not the UI**, whenever the scenario's `Given` is a precondition rather than the behavior under test — e.g. use `actingAs()` + a factory instead of driving the sign-in form when the scenario is about passkey deletion, not sign-in.

See [examples/](examples/) for three complete scenario → Pest translations built on this convention.

_Last updated: 2026-10-01 — Story 0084: the Dashboard vocabulary table gained the actor order administrator and the term payment state (Pending payment, Paid, Partially refunded, Refunded). Still current from story 0083: the **Dashboard vocabulary** subsection gained the actors administrator, super administrator, staff member with no module access, staff member who may only view the media library, and the terms hero and widget; the commerce "order" vs. "sale" TODO stays open. Still current from story 0082 (catalog/order/user manager, Sales, Real income, Orders) and story 0066: the **Internationalization vocabulary** subsection (admin UI language vs. store language). Earlier revision notes live in [history/testing--frontend--gherkin-guidelines.md](../../history/testing--frontend--gherkin-guidelines.md)._
