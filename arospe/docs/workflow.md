# Multi-Agent Development Orchestration (Three Amigos + TDD + Security + Docs)

This is the required workflow the project's specialized Claude Code agents follow to carry a
task from definition to closure, following Three Amigos, TDD, security review, code review,
and continuous documentation. It governs the *agents' process*; it is distinct from
[contracts.md](contracts.md) (per-agent behavioral rules) and [conventions/](conventions/)
(code style). The nine agents referenced below exist as real definitions in
`.claude/agents/`.

## Role

You are the orchestrator of a team of specialized agents that carry a task from definition
to closure, following Three Amigos, TDD, security review, code review, and continuous
documentation. You must strictly respect the phase order and the branching/return
conditions described below. Do not move to the next phase until the exit condition of the
previous one is met.

## Parts

This document is split into parts. Each block below gives the **binding core** of the rules in that part — enough to comply in the common case. Open the full part when its *Read the full text when* line applies to your task, or when you need the exact protocol, rationale or an example. Every part carries the original text unchanged and every heading keeps its original anchor name.

### [Agent roster, task storage and epic digests](workflow/agents-and-epic-digests.md)

**Read the full text when:** you need to know which agent does what, the `ai-spec/tasks/` → `in-progress/` → `done/` convention, or the per-epic decision digest.

- Nine agents with single responsibilities: `product-owner`, `backend-expert`, `frontend-expert`, `database-expert` (only when the data model is touched), `backend-qa`, `frontend-qa`, `appsec-auditor`, `code-reviewer`, `docs-keeper`.
- A task file lives in `ai-spec/tasks/` (new) → `ai-spec/tasks/in-progress/` (when Phase 3 implementation starts) → `ai-spec/tasks/done/` (Phase 7).
- Before opening a prior sibling story in full, check the epic's decision digest `ai-spec/tasks/_digests/epic-<n>.md`.

### [Link integrity, coordination files, classification and ordering](workflow/task-files-links-and-ordering.md)

**Read the full text when:** you create, move or edit the dependencies of a task file, or classify/split/order a task.

- Every stage move of a task file needs the **two-direction link-integrity check** (the moved file's own outbound links, and inbound links from files that never moved) **and** regeneration of `ai-spec/tasks-map.md` and `ai-spec/tasks-status.json` in the same pass (never drop a live `claimed` entry).
- Classify a task before debating: Frontend / Backend / Full-stack (split into two linked tasks, **backend numbered first**) / involves a database (adds `database-expert`). Order tasks so a dependency's number is lower than its dependents'.

### [Flow diagram and Phases 1–7](workflow/phases.md)

**Read the full text when:** you run or resume any phase of a task, or need a phase's exit condition and return loop.

- Respect the phase order; do not advance until the previous exit condition is met: **1** Three Amigos debate → User Story in `ai-spec/tasks/`; **2** INVEST validation (`code-reviewer`); **3** TDD (task moves to `in-progress/`, tests red → code green, loop until green); **4** security audit (`appsec-auditor`, loop back on findings); **5** final code review (acceptance criteria, conventions, DoD, **full** suite passes); **6** documentation (`docs-keeper`); **7** closure (move to `done/`, link-integrity check, PR against the worktree's base branch, `/watch-ci after-push`; never merge).
- No agent advances a task without leaving an explicit approval/rejection record in the task file.

### [User Story template and governance notes](workflow/user-story-template-and-governance.md)

**Read the full text when:** you write a User Story (Phase 1 output) or its Gherkin scenarios.

- A User Story has: Description, Type, Gherkin, Files to create/modify, Tests to perform, Expected outcome, Acceptance criteria, Definition of Done. Gherkin scenarios open with a named business-role actor (never `Given I ...`) and have a single `When` per scenario.

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no rule changed. The prior revision-history footer, if any, stays at the end of the last part._
