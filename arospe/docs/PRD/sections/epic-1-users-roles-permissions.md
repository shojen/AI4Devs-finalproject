# Arospe PRD — Epic 1 — Users, Roles & Permissions

> Part of [Arospe PRD](../PRD.md). **Read this part when:** the task is a Users, Roles or Permissions story. The other parts are listed in the [hub](../PRD.md#table-of-contents).

## Epic 1 — Users, Roles & Permissions

**Priority: 1 (foundation — build first).** Everything else gates on permissions existing.
This epic wires `spatie/laravel-permission`'s `HasRoles` trait onto `User` (currently imported
but not attached — see [`docs/architecture/authorization.md`](../../architecture/authorization.md))
and builds the management UI on top of it.

Two capabilities: (a) the **Users** screen from the prototype — list users, create/edit them in
a modal, assign a role and a status; and (b) a **Roles & Permissions** management area
(*extends the prototype*, which only had a static role dropdown).

The user **status** (Activo / Inactivo / Suspendido) is **not** a purely informational label: a
non-*Activo* status must actually **block that user from logging into the dashboard**,
integrated with the existing Fortify auth flow (see
[`docs/architecture/authentication.md`](../../architecture/authentication.md)).

> **Technical note — `users` primary key becomes a UUID (v7).** Per
> [assumption 19](foundations.md#assumptions--confirmed-decisions), `users` moves to a UUIDv7 primary key via
> Laravel's `HasUuids` trait. `users` is the **only** one of the seven UUID entities that already
> exists in real, migrated code (today a `bigint` autoincrement), so this is a **breaking
> alteration-with-backfill migration, not a fresh `create_table`** — and it cascades to three
> real dependents that must be retyped in step: `passkeys.user_id`, `sessions.user_id`, and
> `spatie/laravel-permission`'s polymorphic `model_has_roles` / `model_has_permissions`
> (`model_id` / morph-key column, with `config/permission.php`'s `model_morph_key` renamed per the
> package's guidance for non-integer-keyed models). Whoever implements Epic 1 should expect real
> migration work here — this is **not** yet implemented and is called out so it's no surprise.

In the Roles & Permissions management area, admins create, edit, and delete custom roles and
toggle **granular permissions per module**. The permission-gated modules cover every management
area across all five epics, plus settings:

- **Users & Roles**
- **Products** (with categories & variants)
- **Sales Regions & Taxes**
- **Shipping**
- **Payment Methods**
- **Customers**
- **Orders**
- **Blog** (with categories & tags)
- **Store Languages** (Internationalization settings)

**Super Admin role.** Exactly **one Super Admin role** exists in the system. It is
**categorically undeletable, uneditable, and cannot be downgraded** — not merely "the last of
its kind", it can never be modified or removed at all. It is assignable **only via direct
database access or a seeder**, never through the dashboard: it is **not** listed in the roles
list, **not** offered when assigning a user's role, and **not** editable anywhere in the
frontend. The Super Admin bypasses permission checks entirely.

**Managing roles at all is a gated permission.** The ability to create/edit/manage roles &
permissions is itself a permission, held by default by the Super Admin and by a seeded baseline
**"Administrator"** role. Other custom roles — such as "Blog Editor" — do **not** have it unless
the Super Admin explicitly grants it.

**A stricter, separate permission gates administrator-level management.** Deleting/editing
administrator-level roles, or downgrading administrator-level users, requires a distinct
**"manage administrator-level roles/users"** permission. **"Administrator-level" refers
specifically to the seeded baseline "Administrator" role** — no other custom role, however broad
its permissions, counts as administrator-level. By default **only the Super Admin** holds this
permission — not even the seeded "Administrator" role. The Super Admin may grant it to a role, but
it carries a meta-rule: **only the Super Admin can even see the option to grant it.** No other
administrator, however broad their permissions (even one holding the general "manage roles &
permissions" permission), ever sees or can grant that control.

![Users list](../images/02-usuarios-lista.png)
*Users list: avatar, name/email, assigned role, a status badge (Activo / Inactivo /
Suspendido), and per-row edit/delete actions. The section header shows a live count
("6 usuarios · 4 activos") and a primary "Nuevo usuario" button.*

![Create / edit user modal](../images/03-usuarios-modal.png)
*Create/edit user modal: full name, email, a **Rol** select populated from the dynamic roles,
and a **Estado** select. This is where a user is assigned one of the roles managed in the
Roles & Permissions area.*

### Gherkin — Users

```gherkin
Feature: User management

  Scenario: Create a user with a role and status
    Given a user administrator, with at least one role available
    When they create a user with a name, a unique email, a role, and a status
    Then the new user appears in the users list with that role and a status badge

  Scenario: Creating a user with a duplicate email is rejected
    Given a user administrator, with an existing user whose email is "marta.ruiz@arospe.es"
    When they try to create another user with the email "marta.ruiz@arospe.es"
    Then creation is rejected with a validation message
    And no second user is created

  Scenario: Change a user's role
    Given a user administrator, with a user "Diego Ferrer" holding the role "Editor"
    When they change that user's role to "Administrador"
    Then the user's role is updated in the list

  Scenario: Deleting a user soft-deletes the record
    Given a user administrator, with an existing user "Diego Ferrer"
    When they delete that user
    Then the user is soft-deleted (marked deleted, not physically removed) so
      historical references are preserved
    And the user no longer appears in the active users list

  Scenario: A regular administrator cannot delete a user holding the "Administrator" role
    Given an administrator without the "manage administrator-level roles/users" permission
    When they try to delete another user who holds the seeded "Administrator" role
    Then the action is denied server-side

  Scenario: A regular administrator cannot downgrade a user holding the "Administrator" role
    Given an administrator without the "manage administrator-level roles/users" permission
    When they try to downgrade another user who holds the seeded "Administrator" role
    Then the action is denied server-side

  Scenario Outline: A non-active user cannot sign in
    Given a user whose status is "<status>"
    When that user tries to sign in
    Then sign-in is refused and no session is granted
    And they are told the account is not active

    Examples:
      | status     |
      | Inactivo   |
      | Suspendido |

  Scenario: Reactivating a user restores sign-in
    Given a user who was blocked from signing in because their status was "Suspendido"
    When a user administrator sets that user's status back to "Activo"
    Then the user can sign in again on their next attempt
```

### Gherkin — Roles & Permissions (extends the prototype)

```gherkin
Feature: Dynamic roles and granular permissions

  Scenario: Create a custom role with scoped permissions
    Given a user administrator
    When they create a role "Blog Editor" granted only the Blog module permissions
    Then the role is saved with exactly those permissions
    And it becomes selectable when assigning a role to a user

  Scenario: A role limits its holder to the granted modules
    Given a blog editor whose role grants only Blog permissions
    When they sign in
    Then they can access the Blog module
    And they cannot access Users & Roles, Products, Sales Regions & Taxes, Shipping,
      Payment Methods, Customers, Orders, or Store Languages settings
    And the sidebar hides the modules they cannot access

  Scenario: "Blog Editor" cannot manage roles at all
    Given a blog editor whose role was not granted the "manage roles & permissions" permission
    When they look for the Roles & Permissions management area
    Then it is not available to them, and they cannot create, edit, or manage any role

  Scenario: Editing a role updates all of its holders
    Given a user administrator, with three users sharing the role "Blog Editor"
    When they remove the "delete blog content" permission from that role
    Then none of those three users can delete blog content afterwards

  Scenario: Deleting a role still assigned to users is hard-blocked with a count
    Given a user administrator, with the role "Blog Editor" assigned to 3 users
    When they try to delete the "Blog Editor" role
    Then deletion is always blocked (no confirm-and-proceed path)
    And the message states how many users hold it
      (e.g. "This role is assigned to 3 users and cannot be deleted")
    And they must reassign those users to another role before it can be deleted

  Scenario: Direct access without permission is denied server-side
    Given a blog editor without Products permissions
    When they navigate directly to a Products URL
    Then access is denied server-side, not merely hidden in the UI

  Scenario: The Super Admin role cannot be deleted
    Given a user administrator with role-management permission
    When they attempt to delete the Super Admin role
    Then it is impossible — the Super Admin role is categorically undeletable

  Scenario: The Super Admin role cannot be edited or downgraded
    Given a user administrator with role-management permission
    When they attempt to edit or reduce the Super Admin role's permissions
    Then it is impossible — the Super Admin role is categorically unmodifiable

  Scenario: The Super Admin role is invisible in the frontend
    Given a user administrator using the dashboard
    When they view the roles list and the user role selector
    Then the Super Admin role appears in neither
    And it can be assigned only via direct database access or a seeder

  Scenario: Only the Super Admin sees the administrator-management grant option
    Given a signed-in Super Admin editing a role's permissions
    When they open that role's permission toggles
    Then they can see and toggle the "manage administrator-level roles/users" permission

  Scenario: A broad administrator never sees the administrator-management grant option
    Given an administrator who holds the general "manage roles & permissions" permission
      but is not the Super Admin
    When they edit a role's permissions
    Then the "manage administrator-level roles/users" toggle is not shown to them

  Scenario: The Super Admin grants a role administrator-management permission
    Given a signed-in Super Admin
    When they grant a custom role the "manage administrator-level roles/users" permission
    Then holders of that role can delete/edit the seeded "Administrator" role and
      downgrade users who hold it
```

**Acceptance criteria**

- [ ] `HasRoles` is attached to `User`; roles/permissions are enforced by middleware/policies,
      not just hidden in the UI.
- [ ] `users` uses a UUID (v7) primary key via Laravel's `HasUuids` trait, applied at both the
      migration and Eloquent model level, replacing the current `bigint` PK — a breaking
      alteration-with-backfill migration that also retypes `passkeys.user_id`, `sessions.user_id`,
      and the `spatie/laravel-permission` polymorphic morph-key (with `model_morph_key` updated).
- [ ] Admins can create, edit, and delete custom roles and toggle granular permissions per
      module across all epics: Users & Roles, Products (categories & variants), Sales Regions &
      Taxes, Shipping, Payment Methods, Customers, Orders, Blog (categories & tags), and Store
      Languages settings.
- [ ] The Users screen lists, creates, edits, and soft-deletes users with a role and a status,
      matching the prototype (list + modal, live count, status badges).
- [ ] Users are **soft-deleted** (marked deleted, not physically removed) to avoid orphaning
      historical references.
- [ ] A non-*Activo* status (Inactivo / Suspendido) blocks that user from logging into the
      dashboard, enforced within the Fortify auth flow; restoring *Activo* restores login.
- [ ] Email is unique and validated; duplicate emails are rejected.
- [ ] Exactly one **Super Admin** role exists; it is categorically undeletable, uneditable, and
      cannot be downgraded, is assignable only via direct DB access or a seeder, is invisible in
      the roles list and user role selector, and bypasses permission checks.
- [ ] Deleting/editing the seeded "Administrator" role or downgrading users who hold it
      ("administrator-level" = specifically that seeded role, no other custom role) requires the
      distinct "manage administrator-level roles/users" permission — held by default only by the
      Super Admin; only the Super Admin can see the control to grant it to a role.
- [ ] Managing roles at all requires a "manage roles & permissions" permission, held by default
      by the Super Admin and a seeded baseline "Administrator" role, and by no other role unless
      granted.
- [ ] The sidebar and every module gate visibility and access on the current user's permissions.
- [ ] A role in use cannot be deleted (hard block, no confirm-and-proceed); the message states
      how many users hold it, and the admin must reassign those users to another role first.

---
