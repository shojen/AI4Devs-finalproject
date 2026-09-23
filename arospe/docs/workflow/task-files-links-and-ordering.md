# Multi-Agent Development Orchestration — Link integrity, coordination files, classification and ordering

> Part of [Multi-Agent Development Orchestration](../workflow.md). **Read this part when:** you create, move or edit the dependencies of a task file, or classify/split/order a task. The other parts are listed in the [hub](../workflow.md#parts).

## Link-integrity check on every stage move

Moving a task file breaks relative links in **two directions**, and both have to be repaired as
part of the same move. Nothing about the move itself signals either one — a `git mv` changes a
file's location, never any file's content, so both kinds of break sit silent until someone
actually clicks the link.

**Every time `product-owner` moves a task file between stages** (Phase 3 step 0, and Phase 7),
`docs-keeper` performs both checks below and fixes everything they turn up, as part of the same
move.

### Direction 1 — the moved file's own outbound links

`./ai-spec/tasks/<file>.md` sits two directory levels below the repo root, but
`./ai-spec/tasks/in-progress/<file>.md` and `./ai-spec/tasks/done/<file>.md` sit **three** — one
deeper. A relative link written for the `new` stage (e.g. `../../docs/PRD/PRD.md`, correct from
`ai-spec/tasks/`) silently breaks the moment the file moves to `in-progress/` or `done/`, because
it now resolves one directory too shallow (`ai-spec/docs/PRD/PRD.md`, which doesn't exist)
instead of `../../../docs/PRD/PRD.md`.

Check every relative link the moved file contains — both that the path still resolves to a real
file and, for a link carrying a `#fragment`, that the anchor still matches a real heading in the
target — and fix any that don't.

`../../docs/…` links going one level too shallow are the obvious case, but a **bare sibling-task
link** breaks on the same move for the mirror-image reason: `](0012-….md)` resolves fine from
`ai-spec/tasks/`, and stops resolving the moment *this* file goes a level deeper while the
sibling stays put. It needs a `../` prefix added. Story 0010 hit exactly this at Phase 3 and
found both instances only because the check was re-run later.

Note the depth change only happens on the **first** move (`new` → `in-progress/`).
`in-progress/` → `done/` is a same-depth move, so a file's own outbound links need no
re-resolution at Phase 7 — but Direction 2 still does, which is exactly why it must be run
separately rather than folded into a "did the depth change?" shortcut.

### Direction 2 — inbound links *to* the moved file, from files that never moved

The mirror image, and the easier one to forget precisely because the citing files are untouched
by the move and so never come up while reviewing it: **every other file that links to the task
by its old path now points at a location that no longer exists.** Those files are not part of
the story being closed, are not in its diff, and will not be opened by anyone working on it.

So a stage move must also **grep the whole repository for the moved file's basename** and
re-point every hit, computing the correct path from *each citing file's own directory depth* —
not by copying one replacement across all of them:

```bash
# From the repo root, after the move:
grep -rn "<basename>.md" --include="*.md" .
```

Then, for each hit, resolve the link target from the citing file's directory and confirm the
file is really there — verify by resolution against the filesystem, never by pattern-matching
that the string "looks right":

```bash
realpath -m "$(dirname <citing-file>)/<link-target>"
```

Two things this catches that a naive find-and-replace does not. A citing file in
`ai-spec/tasks/` needs a `done/` (or `in-progress/`) **segment inserted** — `](0010-….md)` →
`](done/0010-….md)`. A citing file already in `ai-spec/tasks/done/` is now in the *same*
directory as the target, so its `../` prefix must be **removed** — `](../0010-….md)` →
`](0010-….md)`. The same edit applied uniformly would break one of the two groups.

Skip bare mentions in prose or code spans (`` `0010-….md` `` with no `](…)` target) — those are
not links and need no path.

### Both directions have already bitten this project

Direction 1: six already-`done` task files (`0002`–`0006b`) were found with exactly that break
and fixed. Direction 2: closing story `0010` surfaced ten stale inbound links across four files
(`0011`, `0012`, `0035`, and `done/0009`), all pointing at the story's original
`ai-spec/tasks/` path — broken since its *Phase 3* move, and only noticed three phases later at
closure. See [errors-log.md](../errors-log.md) for the first incident and the concrete fix pattern.

### Regenerating the task-coordination files

Two more files derive from the same `ai-spec/tasks/` tree this section governs, and
`docs-keeper` regenerates the affected part of both in the same pass as the link-integrity
check above — never as a separate, later pass that could be skipped independently of the
mandatory check: [`ai-spec/tasks-map.md`](../../ai-spec/tasks-map.md) (the pending-task dependency
graph and flat `done/` inventory) and [`ai-spec/tasks-status.json`](../../ai-spec/tasks-status.json)
(the machine-readable claim registry two parallel sessions coordinate against —
[`ai-spec/tasks-coordination.md`](../../ai-spec/tasks-coordination.md) owns the claim protocol
itself; this step owns keeping the data both files describe true, not the protocol).

Four events trigger it — the three stage moves this section already covers, plus one that is
not a move at all:

- **A new task file is created** (Phase 1, including a Three Amigos decomposition adding
  several at once): add a row to `tasks-map.md`'s pending inventory table and a node to its
  Mermaid graph, with edges derived the way the file's own "Dependency graph" section already
  documents — quote or paraphrase the task file's own stated dependencies, never infer one from
  numbering alone, and drop any dependency already in `done/` (it contributes no edge, exactly
  like every other satisfied dependency already excluded from the graph). Add a matching entry
  to `tasks-status.json` (`id`, `slug`, `title`, `depends_on`, `touches`, `conflict_risk_with`,
  `claimed_by`/`claimed_at` both `null`), with `status` derived fresh from `depends_on` —
  `"ready"` if every listed dependency is already `done/`, `"blocked"` otherwise.
- **A task moves to `./ai-spec/tasks/in-progress/`** (Phase 3 step 0): it is still pending work,
  only checked out — it keeps its node in the graph and its entry in the JSON. Correct
  `tasks-map.md`'s own note about which of the three stages currently hold files. No fourth
  graph style is needed for "in-progress" specifically: a task reaching this stage will already
  be `"claimed"` in the JSON per the coordination protocol's own step 3, and the existing blue
  `claimed` style already signals "a session has this checked out" — a text note is enough.
- **A task moves to `./ai-spec/tasks/done/`** (Phase 7): remove its node and every edge
  touching it from the graph, move its id into the flat `done/` inventory list (updating that
  epic area's count), and **delete its entry from `tasks-status.json` entirely** — a `done/`
  task is never listed there. Recompute `status` for every remaining task that named it as a
  dependency: an entry whose `depends_on` is now empty moves to `"ready"` in the JSON and to the
  green `ready` style in the graph (unless another session has since claimed it, in which case
  it stays `claimed`/blue).
- **An existing pending task's own "Dependencies" section is edited** — a prerequisite shipped
  out of numeric order, or the story was re-scoped and no longer needs a sibling it used to
  cite: recompute that task's edges in the graph and its `depends_on` array in the JSON from the
  real, current section. A dependency only ever growing is not a safe assumption — re-derive the
  full set, don't diff it.

In every case, derive `status` fresh from `depends_on` rather than trusting whatever value the
files already held before the regeneration, and **never silently reset a live `"claimed"` entry
to `null`** while regenerating — carry a claim (`status`, `claimed_by`, `claimed_at`) forward
unchanged unless the task itself just moved to `done/`, in which case the whole entry is dropped
regardless of its claim state.

## Task classification rule

When a task comes in, `product-owner` classifies it into one of these categories **before**
starting the debate:

- **Frontend** → `frontend-expert` + `frontend-qa` participate.
- **Backend** → `backend-expert` + `backend-qa` participate.
- **Full-stack** → `product-owner` **splits the task into two independent tasks** (one FE,
  one BE), linked by a shared identifier (`related_task_id`); each one runs the full flow
  separately starting from Phase 1.
- **Involves a database** (new model, migration, query change, index, etc.) →
  `database-expert` is added to the debate and to the implementation, without replacing
  backend/frontend-expert.

## Task ordering rule

When a full-stack task is split per the rule above, **the backend task is numbered before its
paired frontend task** (lower `<id>`), and `product-owner` sequences `./ai-spec/tasks/` so the
backend task is picked up for Phase 3 first. The frontend task's Blade/Livewire markup binds to
an interface contract (component public properties, computed properties, actions) that only the
backend task defines — building or testing the view first means building against a contract that
does not exist yet, and the view work is blocked until it does. This mirrors call-site-before-
definition ordering in the code itself: a frontend view is a *consumer* of the backend
component's public surface, not an independent artifact.

This ordering rule extends to any task pair connected by a hard dependency even without a shared
`related_task_id` — e.g. a task whose Livewire component consumes a model/scope/policy another
task defines, or a route-gating task that decorates a route another task registers. In general,
**order tasks so a dependency's number is lower than its dependents' numbers**, following the
same reasoning: implement and test the thing being depended on before the thing depending on it.

When renumbering existing task files to restore this order, update every cross-reference to the
affected task numbers across `./ai-spec/tasks/` (`related_task_id`, `[<id>]` headers, filenames,
and prose mentions in `Description`/`Dependencies`/`Gherkin` sections) — including in files under
`in-progress/` and `done/` that mention a renumbered id, even though those files themselves are
not renumbered or moved. Take particular care with any range notation (e.g. "0003–0008"): a
renumbering is a permutation, not a uniform shift, so a token-by-token substitution can silently
turn a correct range into one that includes or excludes the wrong stories — recompute the
intended set of ids and re-express it as a range (or list) after mapping, rather than
substituting the range's endpoints in place.
