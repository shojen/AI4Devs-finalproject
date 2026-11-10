# [0097] Fix: creating an Administrator user is lost after the step-up password confirmation

> **Status: New — created 2026-11-10 from a user bug report; Phase 1 not yet run (open question OQ-1 needs a user decision).**
> Bug fix on the Users screen, follow-up to story 0015a (decision D4, "accepted UX wart").

## Description
A Super Admin opens "Create user", fills the form with role **Administrator** and saves. The app redirects to the password
confirmation screen; after confirming, the actor lands on the Users list and **the Administrator was never created** — the
form values are gone and nothing tells the actor the creation did not happen. Log line observed:
`local.WARNING: Step-up password confirmation required {"actor_id":"…","action":"users.create","user_id":null}`.

## Type
backend + frontend (single Livewire component, no split needed unless OQ-1 picks a new endpoint) | includes database-expert: no

## Verified facts (investigated 2026-11-10)
- The refusal is **by design** (0015a D6): `App\Actions\Users\CreateUser` calls `EnsureRecentPasswordConfirmation` only on the
  Administrator branch, after `promoteToAdministrator`. Every other role creates without step-up; Super Admin is refused earlier
  (`assign_super_admin_role`). So the bug is **Administrator-only on create**.
- Same class of loss exists on **edit** (role/status/email change of another user) and is documented as the accepted wart in
  0015a D4: `App\Livewire\Users\Index::save()` catches `PasswordConfirmationRequiredException`, sets the intended URL to
  `users.index` and redirects; Livewire state (`name`, `email`, `roleId`, `status`) is not preserved, and nothing resumes the write.
- **Not a session-expiry problem (user-confirmed 2026-11-10):** the prompt is the security step-up itself, shown whenever the
  session holds no recent confirmation (including the very first Administrator creation after login). Fortify's
  `ConfirmablePasswordController` stores `auth.password_confirmed_at` correctly and returns to `users.index`; the defect is only
  that the interrupted creation is neither resumed nor restored.
- A pre-warning exists (`create-modal-reconfirm-notice`) but only once the role is already Administrator; the actor has
  typically filled the form by then and still loses it.

## Open question (blocking)
**OQ-1 — how should the write survive the confirmation?** Options:
- (a) **Restore the form (recommended):** before redirecting, flash only the non-secret fields (`name`, `email`, `roleId`, `status`;
  there is no password on this form) to the session for exactly one redirect; on return, `mount()` reopens the modal prefilled so
  the actor presses Save again. Smallest change, no automatic write after confirmation.
- (b) **Ask first:** when "Administrator" is picked (or the modal opens) and confirmation is stale, send the actor to confirm
  *before* they type, then return to an empty create modal.
- (c) **In-modal password field:** confirm inside the modal. Rejected in 0015a D4; listed for completeness.
Also decide whether the **edit** path gets the same treatment (recommended: yes, same mechanism).

## Gherkin
```gherkin
Feature: Creating an Administrator survives the password confirmation

  Scenario: The create form is restored after confirming the password
    Given a Super Admin with no recent password confirmation
    And the Super Admin filled the create form with name "Ana", email "ana@example.test" and role "Administrator"
    When the Super Admin saves the form and confirms their password
    Then the create modal is open again with name "Ana", email "ana@example.test" and role "Administrator" prefilled

  Scenario: Saving after the confirmation creates the Administrator
    Given the create form was restored after a password confirmation
    When the Super Admin saves the form
    Then the Administrator "Ana" exists and the invitation is sent

  Scenario: Other roles never ask for confirmation
    Given a Super Admin with no recent password confirmation
    When the Super Admin creates a user with an ordinary role
    Then the user is created without a confirmation prompt

  Scenario: Restored form values are used once
    Given the create form was restored after a password confirmation
    When the Super Admin reloads the Users page
    Then the create modal is closed and empty
```

## Files to create/modify
- `app/Livewire/Users/Index.php` (`save()` catch block, `mount()`), `resources/views/livewire/users.blade.php` if the notice changes
- `lang/en/users.php`, `lang/es/users.php` if copy changes
- `tests/Feature/Users/` (create + edit step-up round trip), `tests/Browser/` journey for the create flow
- `docs/architecture/authorization/step-up-and-refusal-logging.md` and `docs/security/step-up-authentication.md` (D4 wart no longer applies)

## Tests to perform
- Feature: Administrator create with no recent confirmation redirects to `password.confirm`, flashes the four fields, creates no row.
- Feature: the flashed values are never read twice, never include anything but the four fields, and are ignored for a different actor.
- Feature: ordinary-role create is unchanged (no redirect, row created); Super Admin assignment still refused.
- Browser: no recent confirmation → create Administrator → confirm password → modal prefilled → Save → row exists.

## Expected outcome
The actor never silently loses an Administrator creation: after confirming their password they return to a prefilled form.

## Acceptance criteria
- Only Administrator creation (and, if OQ-1 includes it, other-user edits) is affected; the step-up guard itself is unchanged.
- Form values survive exactly one confirmation round trip and carry no secret.
- No automatic write happens after confirmation unless OQ-1 explicitly chooses it.

## Definition of Done
Tests green (full suite), Pint, Larastan, docs updated (0015a D4 wart superseded), PR in the project template.

## Dependencies, risks, open technical questions
No task dependencies. Risk: persisting user-supplied form state across a redirect on a security-relevant screen (the reason 0015a D4
left it out) — mitigated by (a) storing only four non-secret fields, flash lifetime of one request, actor-bound.

## Approval record
_None yet — Phase 1 (OQ-1) pending._
