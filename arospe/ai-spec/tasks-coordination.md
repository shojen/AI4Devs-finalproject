# Coordinating two parallel Claude Code sessions on `ai-spec/tasks/`

This is an operational cheat-sheet, not a spec. It explains how to use
[`ai-spec/tasks-status.json`](tasks-status.json) — a lightweight claim registry — so two Claude
Code sessions can each pick up a pending task and work on it at the same time without stepping on
each other's files. For the human-readable dependency graph and the reasoning behind
`depends_on`/`conflict_risk_with`, see [`ai-spec/tasks-map.md`](tasks-map.md).

> ⚠️ **This is a cooperative, git-commit-based convention, not a real distributed lock.** There is
> no server enforcing any of this. It only works if both sessions actually follow steps 1 and 3
> below *before* starting code work — nothing prevents a session that skips them from clobbering
> the other's claim or files.

## Protocol

1. **Before starting a pending task, read the latest committed state.** Open
   `ai-spec/tasks-status.json` and `git pull` (or otherwise confirm your working copy has the
   latest committed version) to see any claim the other session already made.
2. **Pick a task whose `status` is `"ready"` and whose `conflict_risk_with` list contains no task
   another session currently has `"claimed"`.** A `"blocked"` task has an unmet dependency — leave
   it. A `"ready"` task whose `conflict_risk_with` overlaps a currently-`"claimed"` task is
   technically pickable (no formal dependency stops you) but is a real file-overlap risk — prefer a
   different `"ready"` task if one exists, or coordinate explicitly with the other session first.
3. **Claim it, then commit and push the claim BEFORE writing any code.** Set that task's `status`
   to `"claimed"`, `claimed_by` to a short identifying label (e.g. a terminal/session name or
   worktree name), `claimed_at` to the current ISO timestamp — then commit and push just that JSON
   change. This is the lightweight lock announcement the other session will see on its next `git
   pull`. Do not start implementation before this lands.
4. **Keep the JSON in sync with the real `ai-spec/tasks/` workflow.** This is now done for you as
   part of `docs-keeper`'s mandatory link-integrity check on every stage move — see
   [`docs/workflow.md`'s task-coordination-file regeneration step](../docs/workflow.md#regenerating-the-task-coordination-files)
   for exactly what changes and when. In short: when the task moves into
   `ai-spec/tasks/in-progress/` per [`docs/workflow.md`](../docs/workflow.md)'s three-stage
   convention, its `status` here stays `"claimed"` (no change needed); when it reaches Phase 7 and
   lands in `ai-spec/tasks/done/`, its entry is **removed from this file entirely**, since at that
   point `ai-spec/tasks-map.md` and this registry no longer need to track it (it is now a satisfied
   dependency for anything downstream, exactly like every other `done/` task — see
   `tasks-map.md`'s own note on why `done/` tasks aren't tracked in its graph either).

   Once a task is genuinely `done/`, `docs-keeper` also **recomputes `status` for anything that
   depended on it**: a task whose every `depends_on` entry is now gone from this file moves from
   `"blocked"` to `"ready"`. If you spot a stale `status` before the next `docs-keeper` pass
   catches it, fixing it by hand is still fine — the JSON is not a build artifact you're forbidden
   from touching, just one this project no longer relies on you to keep current unassisted.
5. **If you abandon a claimed task, release it.** Revert its `status` back to `"ready"` and clear
   `claimed_by`/`claimed_at` (set both to `null`), then commit. Do not leave a stale claim sitting
   there — it silently blocks the other session from picking up work it could otherwise start
   safely (a `"claimed"` task blocks nothing in `depends_on`/`status` terms, but a courteous session
   still avoids a `conflict_risk_with` match against something someone else appears to be
   mid-way through).

## Field reference (short version)

| Field | Meaning |
| --- | --- |
| `id` / `slug` / `title` | Identify the task; `slug` matches its filename under `ai-spec/tasks/`. |
| `depends_on` | Other **pending** task ids that must be `done` first. An id that was already `done` at the time this registry was generated is not listed here — it's already satisfied. |
| `status` | `"ready"` (no unmet pending dependency, unclaimed) · `"blocked"` (has one) · `"claimed"` (a session has it) · `"done"` (finished, not yet closed via the real workflow — usually you'll just delete the entry instead, per step 4). |
| `claimed_by` / `claimed_at` | Who has it and since when. Both `null` when not claimed. |
| `touches` | The files/directories this task will most likely create or modify — a hint for spotting a real conflict before it happens, not an exhaustive list. |
| `conflict_risk_with` | Other pending task ids that plausibly touch the same file/area even though no formal dependency connects them (shared traits, shared lang files, a shared sidebar/config registry, etc.). Symmetric — if `A` lists `B`, `B` lists `A`. |

`depends_on`/`status`/`conflict_risk_with` are regenerated from `ai-spec/tasks-map.md`'s
dependency graph by `docs-keeper` whenever that graph changes — see
[`docs/workflow.md#regenerating-the-task-coordination-files`](../docs/workflow.md#regenerating-the-task-coordination-files).
The two files must stay in sync; if you ever have to reconcile them by hand, `tasks-map.md`'s
graph is the one derived from the task files' own "Dependencies" sections, so treat it as the
source when the two disagree.
