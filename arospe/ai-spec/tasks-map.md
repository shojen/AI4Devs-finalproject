# Task Map — `ai-spec/tasks/` Inventory and Dependency Graph

**This file maps parallelization risk for pending work only.** `done/` tasks are intentionally
left out of the dependency graph below — a merged, closed task can only ever be a *satisfied*
dependency for something still pending, never a source of conflict for work still being
implemented, so drawing it as a node would only add clutter with no decision value.

Aggregated view of every task file under `ai-spec/tasks/` (the three-stage `ai-spec/tasks/` →
`ai-spec/tasks/in-progress/` → `ai-spec/tasks/done/` convention documented in
[`docs/workflow.md`](../docs/workflow.md)). **This file was originally a point-in-time,
manually-regenerated snapshot; it no longer is.** Per
[`docs/workflow.md`'s task-coordination-file regeneration step](../docs/workflow.md#regenerating-the-task-coordination-files),
`docs-keeper` now updates the affected part of this file on the same pass as the mandatory
link-integrity check, every time a task file is created, moves to `in-progress/`/`done/`, or has
its own "Dependencies" section edited — so it should reflect the real backlog rather than
needing a manual "is this stale" check. `docs/workflow.md`'s per-story `ai-spec/tasks/` files
remain the source of truth for any individual story's dependencies; this file only aggregates
and cross-references what those files already state — if the two ever disagree, the individual
task file is correct and this one needs a refresh.

As of this snapshot, `ai-spec/tasks/in-progress/` is empty again: `0048-order-line-item-editing-
backend.md` completed Phase 7 and moved straight from `in-progress/` to `done/` in this same
regeneration pass — `docs-keeper` moved it and ran the
[link-integrity check](../docs/workflow.md#link-integrity-check-on-every-stage-move) this same
pass, per [`docs/workflow.md`'s task-coordination-file regeneration
step](../docs/workflow.md#regenerating-the-task-coordination-files). Its node is dropped from the
dependency graph below (per this file's own "`done/` tasks are omitted from the graph" rule) and
its entry in `tasks-status.json` was deleted outright — a `done/` task is never listed in that
registry. `0048` had `depends_on: []` of its own (its only real prerequisite, `0045`, was already
`done/` when it was claimed), and only `0055` named it as a hard (`depends_on`) blocker — `0055`
dropped `"0048"` from its own `depends_on` array in the same pass, but stays `blocked` on its six
other still-pending Orders dependencies (`0049`/`0050`/`0051`/`0052`/`0053`/`0054`). Reconciled into
this same pass: `0047-customer-order-history-view-ui.md` closed on its own branch and merged via
PR #13 into `finalproject-ARP` while `0048` was in progress — its own dependency-graph and
coordination-file changes (moving `in-progress/` → `done/`, deleting its `tasks-status.json` entry,
dropping `0047` from `0055`'s `conflict_risk_with` array) arrive here as a `git merge` of
`origin/finalproject-ARP` into this branch, resolved in the same pass as `0048`'s own closure —
the same reconciliation shape `0044`'s and `0045`'s closures already used two paragraphs below.
Before that, `0046-orders-new-order-notification-backend.md` completed Phase 7 and moved straight from
`in-progress/` to `done/` in a prior regeneration pass — nothing pending named it as a hard
blocker either, the only stories referencing it (`0056`, `0057`) held it as a soft/informational,
non-blocking sibling. Before that, `0044-customers-list-create-edit-ui.md` and
`0045-orders-core-crud-backend.md` both completed Phase 7 and moved to `done/` in the prior
regeneration pass (0044 on its own branch, merged via PR #9; 0045 checked out into `in-progress/`
for Phase 3, then closed in that same pass). Together their closure freed `0046`, `0047`, `0048`,
`0049`, `0051`, `0053` and `0054` into `ready` with no pending dependency left for any of them (see
the note under
[Pending tasks that are independent](#pending-tasks-that-are-independent-of-each-other-and-safe-to-parallelize)
for what else this closure unblocked) — `0045` was, before that pass, the single biggest hub in the
backlog, so that remains the largest one-story unblock this map has recorded to date. Every other
task is either `done/` (closed, merged) or still sitting directly in `ai-spec/tasks/` (not started).
One additional file, `ci-database-connection-gap.md`, lives outside the `00XX-` numbering — it is an
infrastructure fix (not a PRD-derived user story) and is already marked `Status: fixed and fully
documented` inside its own file, so it is listed for completeness but excluded from the dependency
graph and from the parallelization analysis below.

- **95 files total**: 94 numbered user stories (63 `done/`, 31 still in `ai-spec/tasks/`, 0 checked
  out to `ai-spec/tasks/in-progress/`) + 1 non-numbered infrastructure doc (already resolved).
- For a machine-readable, per-task claim registry that two parallel Claude Code sessions can use
  to coordinate against this same dependency data, see
  [`ai-spec/tasks-status.json`](tasks-status.json) and its companion protocol,
  [`ai-spec/tasks-coordination.md`](tasks-coordination.md).

## Table of contents

- [Inventory](#inventory)
  - [Done (63) — shipped, out of scope for this graph](#done-63--shipped-out-of-scope-for-this-graph)
  - [Pending — not started (31 numbered + 1 infra doc)](#pending--not-started-31-numbered--1-infra-doc)
- [Dependency graph (pending tasks only)](#dependency-graph-pending-tasks-only)
- [Analysis](#analysis)
  - [Pending tasks that are independent of each other and safe to parallelize](#pending-tasks-that-are-independent-of-each-other-and-safe-to-parallelize)
  - [Pending tasks that must be sequenced](#pending-tasks-that-must-be-sequenced)
  - [File/merge-conflict risk even where no formal dependency exists](#filemerge-conflict-risk-even-where-no-formal-dependency-exists)
  - [Scope and known limitations of this map](#scope-and-known-limitations-of-this-map)

## Inventory

### Done (63) — shipped, out of scope for this graph

Already merged into `main`/`finalproject-ARP` and closed via the workflow's Phase 7; see
[`ai-spec/tasks/done/`](tasks/done/) for each story's full file. Listed here only as IDs, grouped
by the epic area they belong to, since — per the note at the top of this file — none of them
appears as a node in the dependency graph below:

- **Epic 1 — Users, Roles & Auth (20):** 0001, 0002, 0003, 0004, 0005, 0006, 0006b, 0007, 0008,
  0008a, 0009, 0010, 0011, 0012, 0013, 0014, 0015, 0015a, 0015b, 0040.
- **Epic 2 — Products, Taxes, Media, Shipping (35):** 0016, 0017, 0018, 0019, 0019a, 0019b,
  0019c, 0019d, 0020, 0021, 0022, 0023, 0024, 0024a, 0024b, 0025, 0026, 0027, 0028, 0029, 0029a,
  0029b, 0030, 0030a, 0031, 0031a, 0032, 0033, 0034, 0035, 0036, 0037, 0038, 0039, 0080.
- **Epic 3 — Customers & Orders (8):** 0041 — the epic's foundation story (the first to close in
  this epic); its own three former dependents (0042, 0043, 0045) are re-derived against `done/`
  rather than against this pending list from here on. 0042 — Customers soft delete (backend), the
  second story to close in this epic; its own two former dependents (0044, 0047) are re-derived
  against `done/` from here on. 0043 — the "new customer" notification backend, the third story to
  close, and closed in parallel with 0042 on a separate branch, reconciled into a prior
  regeneration pass; its own former dependents (0044, 0046, 0056, 0065) are likewise re-derived
  against `done/` from here on. 0044 — Customers list + create/edit UI, the fourth story to close;
  its own former dependent (0047) is re-derived against `done/` from here on. 0045 — Orders core
  CRUD backend, the fifth story to close and the epic's biggest hub: its own seven former
  dependents (0046, 0047, 0048, 0049, 0051, 0053, 0054) are all re-derived against `done/` from
  here on. 0046 — Orders "new order" notification (backend), the sixth story to close; it had no
  dependents of its own with a hard `depends_on` edge — `0056`/`0057` held it only as a soft,
  non-blocking sibling — so its closure re-derives nothing further against this pending list.
  0047 — Customer detail — order history view UI, the seventh story to close; `0055` held it only
  as a `conflict_risk_with` sibling (both touch `resources/views/livewire/customers/show.blade.php`),
  never a hard `depends_on` edge, so this closure removes `0047` from `0055`'s `conflict_risk_with`
  list but re-derives no `depends_on`/`status` change against this pending list. 0048 — Order
  line-item editing backend, the eighth story to close; its own only dependent, `0055`, is
  re-derived against `done/` from here on (`0055` still has six other pending dependencies, so it
  stays `blocked`).

### Pending — not started (31 numbered + 1 infra doc)

| ID | Title | Epic area |
| --- | --- | --- |
| 0049 | Order status transition backend | Epic 3 — Orders |
| 0050 | Order manual cancellation backend | Epic 3 — Orders |
| 0051 | Order payment/refund state backend | Epic 3 — Orders |
| 0052 | Order auto-cancel on full refund backend | Epic 3 — Orders |
| 0053 | Order tax Sales-Region resolution — physical products (backend) | Epic 3 — Orders |
| 0054 | Order tax Sales-Region resolution — virtual products (backend) | Epic 3 — Orders |
| 0055 | Orders list + detail/editor UI | Epic 3 — Orders |
| 0056 | Notification viewing — unread count, recent list, mark-as-read (backend) | Epic 3 — Notifications |
| 0057 | Notification bell UI — topbar dropdown, unread indicator, generic per-type rendering | Epic 3 — Notifications |
| 0058 | Blog categories — backend (table, model, create/rename/delete, name validation) | Epic 4 — Blog |
| 0059 | Blog tags — backend (table, model, create/rename/delete, find-or-create, name validation) | Epic 4 — Blog |
| 0060 | Blog tags — management screen (list, create/edit modal, unconditional delete) | Epic 4 — Blog |
| 0061 | Blog posts — core CRUD backend (+ the blog-category in-use delete guard) | Epic 4 — Blog |
| 0062 | Blog categories — management screen (list, create/edit modal, blocked delete) | Epic 4 — Blog |
| 0063 | Blog posts — list + editor UI | Epic 4 — Blog |
| 0064 | Scheduled post auto-publish — backend (the app's first scheduled command) | Epic 4 — Blog |
| 0065 | Blog post published — notification (backend) | Epic 4 — Blog |
| 0066 | Admin UI locale preference & resolution — backend | Epic 5 — i18n |
| 0067 | Admin UI language switcher — frontend | Epic 5 — i18n |
| 0068 | Store Languages catalog + the app's two default-locale settings | Epic 5 — i18n |
| 0069 | Store Languages settings screen — frontend | Epic 5 — i18n |
| 0070 | Translatable content mechanism — backend, piloted on Product Categories | Epic 5 — i18n |
| 0071 | Product Categories taxonomy screen — language tabs (frontend) | Epic 5 — i18n |
| 0072 | Translatable content retrofit — Blog Categories backend | Epic 5 — i18n |
| 0073 | Blog Categories screen — language tabs | Epic 5 — i18n |
| 0074 | Translatable content retrofit — Blog Tags backend | Epic 5 — i18n |
| 0075 | Blog Tags screen — language tabs | Epic 5 — i18n |
| 0076 | Translatable content retrofit — Products backend | Epic 5 — i18n |
| 0077 | Product editor — language tabs (UI) | Epic 5 — i18n |
| 0078 | Translatable content retrofit — Blog Posts backend | Epic 5 — i18n |
| 0079 | Blog post editor — language tabs (frontend) | Epic 5 — i18n |
| _(no number)_ | Infrastructure fix: no test suite can open a database connection (local fresh setup or CI) — **status: already fixed and documented**, kept out of the numbering and out of the dependency graph below | Infrastructure |

## Dependency graph (pending tasks only)

Edges were derived from each task file's own **"Dependencies"** (or, for Epic 5 files, **"5.
Dependencies, risks, open technical questions"**) section — hard/blocking dependencies, explicit
"depends on story NNNN" statements, and file-relative links to sibling task files. No dependency
below is inferred purely from task numbering; every edge quotes or paraphrases language the
source task file states about itself. A dependency on an already-`done` task (e.g. `0047`'s
mention of `done/0045`) is **not** drawn — it is already satisfied and contributes nothing to a
parallelization decision — which is why several nodes below have no incoming edge at all even
though their own task file lists real prerequisites: those prerequisites are simply already
shipped.

Two edge styles:

- **Solid arrow (`-->`)** — a hard/blocking dependency: the task file itself says Phase 3 cannot
  start, or a specific class/table/contract is consumed, before the upstream task is `done`.
- **Dashed arrow (`-.->`)** — a soft, informational, sibling, or "shares one file/artifact"
  relationship: explicitly called out in the task file, but not stated as blocking.

Nodes with **no incoming edge at all** (green, `ready` style) have every one of their real
prerequisites already in `done/` — nothing pending stands between them and Phase 3.

```mermaid
flowchart LR
    classDef pending fill:#fef9c3,stroke:#ca8a04,color:#713f12,stroke-width:1px;
    classDef ready fill:#dcfce7,stroke:#16a34a,color:#14532d,stroke-width:1px;
    classDef claimed fill:#dbeafe,stroke:#2563eb,color:#1e3a8a,stroke-width:1px;

    subgraph PEND_ORD["Epic 3 — Orders"]
        direction TB
        P0049["0049 Status transition BE"]
        P0050["0050 Manual cancellation BE"]
        P0051["0051 Payment/refund state BE"]
        P0052["0052 Auto-cancel full refund BE"]
        P0053["0053 Tax region — physical BE"]
        P0054["0054 Tax region — virtual BE"]
        P0055["0055 Orders list/detail UI"]
    end

    subgraph PEND_NOTIF["Epic 3 — Notifications"]
        direction TB
        P0056["0056 Notification viewing BE"]
        P0057["0057 Notification bell UI"]
    end

    subgraph PEND_BLOG["Epic 4 — Blog"]
        direction TB
        P0058["0058 Blog categories BE"]
        P0059["0059 Blog tags BE"]
        P0060["0060 Blog tags UI"]
        P0061["0061 Blog posts core CRUD BE"]
        P0062["0062 Blog categories UI"]
        P0063["0063 Blog posts list/editor UI"]
        P0064["0064 Scheduled auto-publish BE"]
        P0065["0065 Post published notif BE"]
    end

    subgraph PEND_I18N["Epic 5 — Internationalization"]
        direction TB
        P0066["0066 Admin UI locale pref BE"]
        P0067["0067 Admin UI language switcher"]
        P0068["0068 Store Languages catalog BE"]
        P0069["0069 Store Languages UI"]
        P0070["0070 Translatable content mechanism"]
        P0071["0071 Product Categories i18n UI"]
        P0072["0072 Blog Categories retrofit BE"]
        P0073["0073 Blog Categories i18n UI"]
        P0074["0074 Blog Tags retrofit BE"]
        P0075["0075 Blog Tags i18n UI"]
        P0076["0076 Products retrofit BE"]
        P0077["0077 Product editor i18n UI"]
        P0078["0078 Blog Posts retrofit BE"]
        P0079["0079 Blog post editor i18n UI"]
    end

    %% Customers and Orders core
    %% (0042/0043 -> 0044, and 0041/0024/0029/0035/0036/0038 -> 0045, are all satisfied now that
    %% every one of those is done/; no edge drawn, per this file's own "a dependency on an
    %% already-done task is not drawn" convention)

    %% Orders siblings
    %% (P0048 --> P0055 dropped: 0048 closed to done/ this pass, and per this file's own "done/
    %% tasks are omitted from the graph" rule its node and edge are removed rather than redrawn
    %% against a done/ id)
    P0049 --> P0050
    P0051 --> P0050
    P0051 --> P0052
    P0050 -.-> P0052
    P0053 -.-> P0054
    P0049 --> P0055
    P0050 --> P0055
    P0051 --> P0055
    P0052 --> P0055
    P0053 --> P0055
    P0054 --> P0055

    %% Notifications
    %% (0046 -.-> P0056 and 0046 -.-> P0057 dropped: 0046 closed to done/ this pass, and per this
    %% file's own "done/ tasks are omitted from the graph" rule its node and edges are removed
    %% rather than redrawn against a done/ id)
    %% (P0047 -.-> P0055 dropped the same way: 0047 closed to done/ this pass — it was only a
    %% conflict_risk_with sibling of 0055, never a hard depends_on edge, so no dependent status
    %% recomputation follows from this removal)
    P0056 --> P0057

    %% Blog
    P0059 --> P0060
    P0058 --> P0061
    P0059 --> P0061
    P0058 --> P0062
    P0061 --> P0062
    P0060 -.-> P0062
    P0058 --> P0063
    P0059 --> P0063
    P0061 --> P0063
    P0060 -.-> P0063
    P0061 --> P0064
    P0061 --> P0065
    P0064 --> P0065

    %% i18n
    P0068 --> P0066
    P0066 --> P0067
    P0068 --> P0067
    P0066 --> P0069
    P0068 --> P0069
    P0067 -.-> P0069
    P0068 --> P0070
    P0068 --> P0071
    P0070 --> P0071
    P0058 --> P0072
    P0070 --> P0072
    P0068 --> P0072
    P0063 -.-> P0072
    P0072 --> P0073
    P0071 --> P0073
    P0062 --> P0073
    P0070 --> P0073
    P0068 --> P0073
    P0059 --> P0074
    P0070 --> P0074
    P0068 --> P0074
    P0063 -.-> P0074
    P0074 --> P0075
    P0060 --> P0075
    P0070 --> P0075
    P0068 --> P0075
    P0071 --> P0075
    P0070 --> P0076
    P0068 --> P0076
    P0076 --> P0077
    P0070 --> P0077
    P0068 --> P0077
    P0061 --> P0078
    P0070 --> P0078
    P0068 --> P0078
    P0063 -.-> P0078
    P0078 --> P0079
    P0063 --> P0079
    P0071 --> P0079
    P0077 -.-> P0079
    P0070 --> P0079
    P0068 --> P0079

    class P0050,P0052,P0055,P0057,P0060,P0061,P0062,P0063,P0064,P0065,P0066,P0067,P0069,P0070,P0071,P0072,P0073,P0074,P0075,P0076,P0077,P0078,P0079 pending;
    class P0049,P0051,P0053,P0054,P0056,P0058,P0059,P0068 ready;
```

Legend: green (`ready`) = unblocked and unclaimed, safe to hand to a new session today; blue
(`claimed`) = unblocked but a session already has it (per
[`tasks-status.json`](tasks-status.json)) — do not start it without checking that registry first
(no node is `claimed` in this snapshot); yellow (`pending`) = still blocked on at least one open
pending dependency.

## Analysis

### Pending tasks that are independent of each other and safe to parallelize

These are the tasks whose **entire dependency chain is already `done/`** — nothing pending blocks
them — the eight green `ready` nodes in the diagram above. (`0046`, `0047` and `0048` all had the
identical property in their own turn — `0046`'s only dependency was `0045`; `0047`'s were `0044`
and `0045`; `0048`'s was `0045` alone — but none of the three is listed anywhere in this section
any more: each closed to `done/` in this or a prior regeneration pass, so per this file's own
"`done/` tasks are omitted from the graph" rule none has a node at all any more. See the note at
the top of this file.)

- **0049 — Order status transition backend.** Its only dependency, `0045`, is now `done/`. Ready
  now — but see [File/merge-conflict risk](#filemerge-conflict-risk-even-where-no-formal-dependency-exists)
  below: it creates `app/Policies/OrderPolicy.php`'s row-state branches that `0050`/`0051`/`0052`/
  `0055` all also write.
- **0051 — Order payment/refund state backend.** Its only dependency, `0045`, is now `done/`.
  Ready now — same `OrderPolicy.php` caveat as `0049`.
- **0053 — Order tax Sales-Region resolution — physical products (backend).** Its only dependency,
  `0045`, is now `done/`. Ready now.
- **0054 — Order tax Sales-Region resolution — virtual products (backend).** Its only hard
  dependency, `0045`, is now `done/` (`0053` remains a soft/informational sibling, not a blocker).
  Ready now.
- **0058 — Blog categories (backend).** "None inside Epic 4 for its schema, model, actions or
  policy… the foundational story the other blog stories build on." Ready now.
- **0059 — Blog tags (backend).** No hard dependency on 0058 in either direction (both depend only
  on already-shipped work plus `done/0022`'s `NormalizeForSearch`). Ready now.
- **0056 — Notification viewing (backend).** Its only dependency, `0043`, is `done/` — depends on
  nothing else pending. Touches `App\Models\User` (the unread-count/mark-as-read surface) and a
  new `app/Actions/Notifications/` namespace. Ready now.
- **0068 — Store Languages catalog (backend).** Depends only on `done/0002` (the
  `store-languages.*` permissions) and cites `done/0016`/`0017`/`0018` only as a *precedent*, not a
  code dependency. Ready now — and, being the root of the entire Epic 5 chain (every i18n story
  ultimately depends on it), it is also the single highest-leverage task to start first if only
  one of these eleven can be picked up immediately.

**`0045` — Orders core CRUD backend — closed in this same regeneration pass, and is what freed the
seven Orders nodes above (`0046`, `0047`, `0048`, `0049`, `0051`, `0053`, `0054`) into `ready` at
once.** It was, before this pass, the single biggest hub in the backlog (see
[Pending tasks that must be sequenced](#pending-tasks-that-must-be-sequenced) below for the chain
it used to gate); its task file moved `ai-spec/tasks/in-progress/` → `ai-spec/tasks/done/`, and its
`tasks-status.json` entry was removed, per
[`docs/workflow.md`'s task-coordination-file regeneration step](../docs/workflow.md#regenerating-the-task-coordination-files).
`0050`, `0052` and `0055` each also named `0045` as a hard blocker and each dropped it from their
own `depends_on` array in the same pass, but stay `blocked` on their *other* still-pending
dependencies (`0050` on `0049`/`0051`; `0052` on `0051`; `0055` on its six remaining Orders
stories) — recomputing a status, not merely stripping a satisfied id, per the same rule this file
applied when `0043` closed (see the note further below).

**0049, 0051, 0053, 0054, 0056 and 0068 are fully independent of each other and
of 0058/0059** — no shared files, no shared tables, and none of them appears in the other's
`conflict_risk_with` set in [`ai-spec/tasks-status.json`](tasks-status.json), with one caveat
carried over unchanged from before this closure: `0049` and `0051` both write
`app/Policies/OrderPolicy.php`'s row-state branches with no dependency edge between them (see
[File/merge-conflict risk](#filemerge-conflict-risk-even-where-no-formal-dependency-exists)) — a
real, undeclared parallel-write hazard the [Parallel Agent File-Ownership
Rule](../docs/contracts.md#parallel-agent-file-ownership-rule) exists to catch. All six can still
be dispatched to parallel agents/worktrees today, with that one pairing coordinated explicitly,
and no other coordination needed beyond the project's usual per-branch worktree isolation (see
[`docs/testing/worktree-databases.md`](../docs/testing/worktree-databases.md)).

**0037, 0038, 0039, 0041, 0042, 0043, 0044 and 0045 already closed** (each went dependency-ready
the moment its own last blocker shipped — `0036` for `0037`, nothing pending at all for `0038`,
`0038` for `0039`, nothing pending at all for `0041`, `0041` for `0042`, `0041` for `0043`, `0042`
+ `0043` for `0044`, and `0041`/`done/0024`/`done/0029`/`done/0035`/`done/0036`/`done/0038` for
`0045` — was claimed via `tasks-status.json` by whichever session picked it up next, and is now in
`done/` too) — a real, worked instance, eight times over, of why the JSON registry exists alongside
this diagram: a task can turn dependency-ready and get claimed by another session before this
snapshot is regenerated, so the JSON's live `status`/`claimed_by` is always the thing to check
before dispatching a "ready" node from here, never this diagram alone. **0041's own closure is
what freed 0042 and 0043 into `ready`; both closed too, in parallel on separate branches, in a
prior regeneration pass.** Combined, closing both freed `0044` (both of its former dependencies,
`0042` and `0043`, were `done/`) and `0056` (its only former dependency, `0043`, was `done/`) into
fully `ready` — with no pending dependency left for either — while `0045` was already `ready` on
its own at that point (`0041` was its last blocker, independent of 0042/0043). **This regeneration
pass is what reconciles 0044's and 0045's own subsequent closures into one snapshot** — 0044 on its
own branch (merged via PR #9), 0045 checked out into `in-progress/` for Phase 3 and closed at the
end of the same session. `0046`, `0047`, `0048`, `0049`, `0051`, `0053` and `0054` each cited `0045`
as a hard blocker (`0047` also cited `0044`), so each dropped it from its own `depends_on` array —
all seven are now fully `ready`, per
[workflow.md](../docs/workflow.md#regenerating-the-task-coordination-files)'s "recompute `status`
for every remaining task that named it as a dependency" rule, which recomputes a status, not
merely strips a satisfied id. `0050`, `0052` and `0055` also cited `0045` and dropped it the same
way, but stay `blocked` on other still-pending dependencies (see the note two paragraphs above).

**0058 and 0059 are a softer case.** Neither blocks the other and both are ready today, but both
land inside `app/Actions/Blog/` (different files — `CreateBlogCategory`/`RenameBlogCategory`/
`DeleteBlogCategory` vs. `CreateBlogTag`/`RenameBlogTag`/`DeleteBlogTag`/`FindOrCreateBlogTag` —
so a git merge between them is mechanically safe) and both extend the same seeded `blog.*`
permission module and the same kind of `lang/en|es/blog.php` file. This is low risk (see
[File/merge-conflict risk](#filemerge-conflict-risk-even-where-no-formal-dependency-exists)) but
worth a quick coordination check (e.g. who creates `lang/{en,es}/blog.php` first) rather than
treating it as zero-risk parallelism.

Once those land, the same "ready" property propagates outward in a few places — **0037**, **0038**,
**0041**, **0042**, **0043**, **0044** and **0045** already did (per the note above), and that is
what freed **0056** and, most recently, **0046**/**0047**/**0048**/**0049**/**0051**/**0053**/
**0054** into `ready` too (**0048** itself has since closed to `done/`); **0050** will follow the
moment **both 0049 and 0051** close; **0052** the moment **0051** closes; **0055** the moment **the
six remaining** of **0049**, **0050**, **0051**, **0052**, **0053** and **0054** close (`0048`, its
seventh former blocker, is already `done/`); **0065** the moment **both 0061 and 0064** close;
the same will also happen for **0060** the moment **0059** closes, **0066**/**0070**/**0071** the
moment **0068** closes, and **0061** the moment **both 0058 and 0059** close — none of those is
parallel-safe to a second session today, only sequential.

### Pending tasks that must be sequenced

The dependency graph above makes most of the backlog a strict sequencing problem rather than a
parallelization one. The major chains, in the order they must be executed:

1. **Customers → Orders (Epic 3).** `0041` (`done/`) unblocked `{0042, 0043}`; both closed too
   (`done/`) in a prior regeneration pass — in parallel on separate branches — which unblocked
   `0044`, now also `done/`. `0045` (Orders core CRUD backend) likewise had its six former hard
   dependencies — `0041` plus `done/0024`/`done/0029`/`done/0035`/`done/0036`/`done/0038` — all
   satisfied, and is now `done/` too: it was the single biggest hub in the backlog, gating `0046`,
   `0047` (which also needed `0044`, now satisfied too), `0048`, `0049`, `0050` (also needs `0049`
   and `0051`), `0051`, `0052` (via `0051`), `0053`, and `0054` — **all seven of those non-`0050`/
   `0052` nodes are now `ready`** (see
   [Pending tasks that are independent](#pending-tasks-that-are-independent-of-each-other-and-safe-to-parallelize)
   above). `0050` and `0052` remain sequenced behind `0049`/`0051` and `0051` respectively.
   **`0055` (the Orders UI) is the epic's terminal node** — its own task file states Phase 3 cannot
   begin until **all seven** of `0048`, `0049`, `0050`, `0051`, `0052`, `0053` and `0054` are
   `done` (`0045` itself already is). `0048` is the first of the seven to get there, closing in
   this same regeneration pass; the six remaining (`0049`, `0050`, `0051`, `0052`, `0053`, `0054`)
   are still pending.
2. **Notifications (Epic 3).** `0043` (`done/`) is what unblocked `0056` — it is `ready` above with
   no pending dependency left at all; `0056 → 0057` next. `0046` was never a hard blocker of either
   — only a soft/informational, non-blocking sibling that made their "two distinct notification
   types" test meaningful — and it is `done/` as of this pass too, so that soft reference is now
   fully satisfied; `0056`'s and `0057`'s own status is unchanged either way.
3. **Blog (Epic 4).** `{0058, 0059} → 0061 → {0062, 0063, 0064 → 0065}`, with `0059 → 0060` running
   in parallel to `0061` (0060 only needs 0059) and `0062`/`0063` each also softly preferring
   `0060` to land first (it creates the `groups.blog` sidebar entry both reuse).
4. **Internationalization (Epic 5).** This is the most heavily sequenced part of the backlog, and
   it is **cross-epic**: every retrofit story blocks on `0068` (Store Languages catalog) and
   `0070` (the translatable-content mechanism, itself gated on `0068`), and each UI-facing i18n
   story additionally blocks on the Epic 4 backend story whose table it retrofits. The full
   strict order, as stated across several of these task files' own "Dependencies" sections:

   ```text
   0068 → 0066 → 0067          (admin UI locale preference, note the inverted numbering — 0066 needs
                                 0068's LocaleSetting piece even though 0066 < 0068 numerically)
   0068 → 0069                 (Store Languages settings UI)
   0068 → 0070 → 0071          (translatable-content mechanism, piloted + UI'd on Product Categories)
   0058 → 0061 → 0062 → 0068 → 0070 → 0071 → 0072 → 0073   (Blog Categories retrofit + i18n UI)
   0059 → 0061 → 0063 → 0068 → 0070 → 0074 → 0075          (Blog Tags retrofit + i18n UI, 0075 also
                                                              needs 0071's shared <x-language-tab-strip>)
   0068 → 0070 → 0076 → 0077                                (Products retrofit + i18n UI — 0024, the
                                                              table itself, is already done)
   0058 → 0059 → 0061 → 0063 → 0068 → 0070 → 0074 → 0078 → 0079   (Blog Posts retrofit + i18n UI)
   ```

   Two things worth calling out explicitly because they are easy to misread from the numbering
   alone: **(a)** `0066` genuinely depends on `0068` despite being numbered lower — this is a
   documented, deliberate exception to the project's usual "dependency is numbered below its
   dependent" convention, not an error in this map; **(b)** `0063` is listed as a (dashed, soft)
   dependency *of* `0072`/`0074`/`0078` in those files' own tables, but the direction that actually
   matters for scheduling is the reverse — `0063` must ship **before** those retrofit stories, so
   they have something to retrofit, and then `0063`'s own queries need a follow-up correction once
   each retrofit lands. It is drawn dashed in the graph to reflect that it is a "will need
   revisiting" relationship, not a blocking prerequisite.

### File/merge-conflict risk even where no formal dependency exists

Flagged explicitly in the source task files, even though no hard dependency edge connects the two
sides. Every pair below is also recorded, symmetrically, in each task's `conflict_risk_with` array
in [`ai-spec/tasks-status.json`](tasks-status.json):

- **`app/Policies/OrderPolicy.php` is written by five different Epic 3 stories** —
  `0049` (creates it), `0050`, `0051` (adds no ability per its own decision, but was in scope
  before that), `0052`, and `0055`. `0050`'s own file states in an explicit warning block: *"This
  story is not parallel-safe with 0049, 0051, 0052 or 0055 — they all write
  `app/Policies/OrderPolicy.php`."* Most of that cluster is already sequenced by a hard dependency
  edge (`0049 → 0050 → {0051, 0052} → 0055`), so the residual, *undeclared* risk is narrower than
  the whole five-way cluster: **`0049` ~ `0051`**, **`0049` ~ `0052`** and **`0050` ~ `0052`** are
  the pairs with no direct dependency edge between them, which is what the project's own
  [Parallel Agent File-Ownership Rule](../docs/contracts.md#parallel-agent-file-ownership-rule)
  exists to prevent two sessions from hitting at once.
- **`lang/{en,es}/orders.php` is written by `0045`, `0049`, `0050`, `0054` and `0055`** — different
  key groups in the same file each time, which is ordinary sequential maintenance where a hard
  dependency already orders the pair, and a real (if minor) conflict risk for the two pairs that
  are *not* already sequenced: **`0049` ~ `0054`** and **`0050` ~ `0054`**.
- **`App\Concerns\ResolvesSalesRegionFromAddress` is a create-if-absent shared trait between `0053`
  and `0054`.** Neither depends on the other, but whichever implementation phase runs first creates
  the file and the second must `use` it unchanged rather than duplicating it.
- **`app/Actions/Blog/` is shared by `0058` and `0059`** (see above) — different files, low risk,
  but worth a quick check on shared conventions (permission-constant naming, lang-file key groups)
  before dispatching both at once.
- **`0060` and `0062`/`0063` (Blog Tags UI vs. Blog Categories UI / Blog Posts list+editor UI)**
  each touch the shared blog sidebar registry (`config/modules.php`'s `groups.blog`) and
  `lang/{en,es}/blog.php` around the same point in the roadmap, without a formal dependency
  forcing an order.
- **`0062` and `0063` are also an explicit parallel-write hazard against each other**, per `0063`'s
  own dependency notes, for the same registry/lang-file reason.
- **The Epic 5 retrofit stories (`0072`, `0074`, `0076`, `0078`) all depend on the same pair,
  `0068` and `0070`**, and each also touches `config/modules.php` / `lang/{en,es}/*.php` for its
  own domain. They do not depend on each other and their tables are disjoint (blog categories vs.
  blog tags vs. products vs. blog posts), so once `0068`/`0070` are both closed, **these four
  retrofit stories are themselves a second, smaller "independent and parallelizable" cluster** —
  with the same caveat as 0058/0059 above about shared registry/lang files, all six pairs among
  them (`0072`~`0074`, `0072`~`0076`, `0072`~`0078`, `0074`~`0076`, `0074`~`0078`, `0076`~`0078`)
  recorded in the JSON registry.
- **`0067` and `0069` collide on the same rendered page** — the personal language switcher (0067)
  renders in the chrome of the Store Languages settings screen (0069), which the source task file
  calls out as *"a real assertion collision (R-3)"* even though 0069 does not depend on 0067's
  code.
- **`0077`/`0079`** carry one softer, non-file-overlap "informational" pairing already shown dashed
  in the graph (a "worked out the UI shape first" precedent) — included in the JSON registry's
  `conflict_risk_with` for completeness, even though the practical collision risk is lower than the
  file-sharing cases above. (`0046`/`0056`/`0057` used to be listed here as the same shape of soft
  pairing; `0046` is `done/` as of this pass, so its entry was dropped from `0056`'s and `0057`'s
  own `conflict_risk_with` arrays in [`ai-spec/tasks-status.json`](tasks-status.json) rather than
  left pointing at a task the registry no longer lists at all.)

### Scope and known limitations of this map

- **The `done/` tasks are omitted from the graph entirely, on purpose** (see the note at the top of
  this file). They are still listed as flat IDs in the [inventory](#done-63--shipped-out-of-scope-for-this-graph)
  above and are still referenced by ID in this analysis' prose where they explain *why* a pending
  task has no incoming edge (i.e. all its real prerequisites already shipped) — but re-deriving a
  full internal dependency graph for 63 already-merged stories would not change anything actionable
  today, so it was not attempted.
- **Several pending task files themselves warn that their own dependency sections may be stale.**
  Many Epic 3/4/5 stories were composed before their prerequisites shipped, and each carries a
  self-aware note along the lines of *"this document goes stale while it waits… every name in this
  file is a reading aid, not a locator"* (a rule this project's own
  [`docs/errors-log.md`](../docs/errors-log-archive.md#a-deferred-storys-findings-were-claims-about-a-tree-that-no-longer-existed-and-one-of-them-would-have-reopened-a-bug-in-this-log--2026-08-23)
  states explicitly). This map inherits that caveat: **before actually starting a pending story,
  re-verify its own "Dependencies" section against `HEAD` rather than trusting this snapshot**,
  especially for any story more than a few positions deep in a chain (0055, 0073, 0079 in
  particular chain through seven or more prerequisites each).
- **One dependency in this map is already stale as written and is called out here rather than
  silently "corrected."** Story `0055`'s own file lists `done/0022` (the searchable multi-select
  component) as an unresolved *"soft dependency, worked around rather than waited on"* — but
  `0022` has since shipped and is in `done/`. No edge was ever drawn for it (it was never a hard
  blocker), but a Phase 2 re-read of `0055` should drop the interim workaround its `D-1` describes
  and use the real component instead.
- **The infra fix `ci-database-connection-gap.md`** is intentionally excluded from the graph — its
  own file's status banner confirms it was fixed and fully documented on 2026-08-26, so it blocks
  nothing today.
