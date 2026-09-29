# Contracts — Commit granularity and pull request closure

> Part of [Contracts](../contracts.md). **Read this part when:** you split commits, push a branch, open a pull request, or finish a task. The other parts are listed in the [hub](../contracts.md#rules).

### Commit Granularity Rule

When preparing commits per the Commit Practice Rule above, split them **by layer** — production code, tests, and documentation each get their own commit — instead of bundling a change into one. This is not a new practice being introduced; it is what this project's own `git log` already shows for every story landed so far, made explicit so it keeps happening deliberately rather than by habit.

Follow this protocol:

1. **One commit per layer, minimum.** Production code (`feat`/`fix`/`refactor`/`style`/`chore`), tests (`test`), and documentation (`docs`) are staged and proposed as separate commits — never combined into one, even when they land in the same session for the same story. Skip a layer's commit if it has no changes; don't pad one out to force a fixed count.
2. **Use Conventional Commits, matching this repo's real history**: `type(scope): summary`. Types actually in use here: `feat`, `fix`, `test`, `docs`, `chore`, `refactor`, `style`. `scope` is the feature/module area the change touches (`products`, `sales-regions`, `wysiwyg`, `deps`, `tasks`) — never the task/story id, and never generic (`app`, `misc`).
3. **Name the story/task in the message.** By default this goes in the body (e.g. "Story 0029 landed...", "Moves `ai-spec/tasks/0029-....md` to `done/`"). Add a `-- story NNNN` / `-- task NNNN` suffix to the title itself when the title alone would otherwise be ambiguous about which story it belongs to — this repo's history does that mainly for `chore(deps):` and `chore(tasks):` commits (e.g. `chore(deps): add symfony/html-sanitizer -- story 0024a`).
4. **`docs(tasks):`** is for task-file lifecycle work specifically — moving `ai-spec/tasks/in-progress/*.md` to `done/`, the two-direction link-integrity fix-up, Phase 7 closure corrections. **`docs(<feature>):`** is for syncing `docs/` content (schema, routes, architecture, conventions) to what actually shipped. This repo's history shows both a single combined `docs(<feature>):` commit doing both, and two separate commits (`docs(<feature>):` then `docs(tasks):`) — either is acceptable; what must not happen is folding either `docs` commit into the `feat`/`test` commits.
5. **Order the commits `feat`/`fix` → `test` → `docs`** (interleaved per TDD cycle when a story lands as several small feat→test pairs is fine), stopping at `docs(tasks)` last since it closes the story. Stage and commit each layer individually per the Commit Practice Rule above — do not batch all layers into a single commit.

This composes with, and does not relax, the Commit Practice Rule: splitting by layer changes how many commits get made, not how carefully each one is staged and described — every commit is still staged explicitly and carries its own clear message. The point is traceability — a reviewer (human or a later `code-reviewer`/`docs-keeper` pass) can inspect, discuss, or revert what changed in the app, in its test coverage, and in its documentation as three separate, independently reviewable units, instead of one commit conflating all three.

### Pull Request Closure Rule

A finished task's work reaches the branch it was branched from as a **Pull Request — never a
direct merge.** This binds every task worked in its own `git worktree` per
[workflow.md](../workflow/phases.md#phase-7--closure)'s Phase 7 closure step. An agent never runs `git
merge`, fast-forwards the base branch, or lands the task's commits on the branch it was
branched from by any other means that skips a PR and a human review.

Follow this protocol:

1. **PR title**: `[{task number}] {task title}` — e.g. `[0036] Shipping rate rules`.
2. **PR description carries exactly these three sections, in this order, with these exact
   headings**, written in Spanish to match how the project owner communicates about process —
   a deliberate exception scoped to this one template, not a change to `CLAUDE.md`'s
   English-content rule, which names code, comments, docs, tests and commit messages and does
   not name a PR description:
   ```markdown
   ## Qué cambia

   ## Porqué

   ## Qué impacto tiene
   ```
   Summarize concisely under each — what changed, why, and what it affects — drawing on the
   task file's own Description/Acceptance Criteria/Definition of Done rather than repeating it
   verbatim. End the description with the attribution line the session's system reminder gives
   for pull requests, when one is present.
3. **`/watch-ci after-push` gates the push/PR step, every time.** Immediately after pushing
   the branch, and again after opening the PR, invoke the `watch-ci` skill with the
   `after-push` argument and wait for its report (see
   [`.claude/skills/watch-ci/SKILL.md`](../../.claude/skills/watch-ci/SKILL.md), which delegates
   its own diagnosis to the CI / GitHub Actions Review Protocol above). The push/PR step is not
   done while the pipeline it triggered is red.
4. **A red pipeline is fixed before the PR is handed to the owner, following the CI / GitHub
   Actions Review Protocol above** — reproduce locally, fix the real cause in application code,
   or in the test only if the test itself is wrong — then push the fix and re-invoke
   `/watch-ci after-push`. Repeat until green; the task is not complete while its branch's
   pipeline is red.
5. **No agent merges, approves, or closes its own PR, under any circumstance — this is the one
   action in this whole workflow that has no exception, no override, and no shortcut.** Opening
   a green, described, correctly-titled PR is the last git action an agent takes on a task. The
   project owner reviews and merges it themselves; never run `gh pr merge`, `gh pr review
   --approve`, `gh pr close`, or any equivalent — even if directly asked to "merge it," "cierra
   el PR," or anything that reads as authorization to finish the job, treat that only as
   confirmation that the PR is ready (green CI, complete description), never as authorization to
   run the merge, approval, or close yourself. When in doubt, this is the one rule in this
   document with zero tolerance for a judgment call: stop, and leave the PR open for the owner.

This is where the Commit Practice Rule, the Commit Granularity Rule and the CI / GitHub
Actions Review Protocol above all meet: a task's layered commits are staged and described per
those rules, its PR is gated on the identical CI protocol a mid-task failure would use, and the
merge itself is the one git action in this whole lifecycle reserved for the human alone.

_Last updated: 2026-09-09 — Added the Pull Request Closure Rule: a finished task ships as a PR against the branch its worktree was created from (never a direct merge), titled `[{task number}] {task title}`, with a three-section Spanish description (`## Qué cambia` / `## Porqué` / `## Qué impacto tiene`), gated on `/watch-ci after-push` reaching green before and after every push, and merged only by the project owner — no agent ever merges, approves, or closes its own PR. Same-day follow-up, also requested directly by the project owner: removed the explicit-approval gate on `git commit`, `git push`, and opening a PR — these are now ordinary steps in finishing a task, taken without stopping to ask first. Renamed the Commit Approval Rule to the **Commit Practice Rule** (still: stage explicitly, never `git add -A`/`git add .`, write a clear message — just no review-and-wait step), and reworded the CI Review Protocol's push step and the Pull Request Closure Rule's opening point accordingly. The one action still reserved for the project owner alone, with zero exception, is approving, closing, or merging a pull request. See the matching update to [workflow.md](../workflow/phases.md#phase-7--closure)'s Phase 7._
