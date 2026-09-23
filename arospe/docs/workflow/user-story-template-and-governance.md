# Multi-Agent Development Orchestration — User Story template and governance notes

> Part of [Multi-Agent Development Orchestration](../workflow.md). **Read this part when:** you write a User Story (Phase 1 output) or its Gherkin scenarios. The other parts are listed in the [hub](../workflow.md#parts).

## User Story template (mandatory output of Phase 1)

Every scenario below — and every scenario in `docs/PRD/` — must follow
[testing/frontend/gherkin-guidelines.md](../testing/frontend/gherkin-guidelines.md)'s rules 1
("Imperative vs. declarative scenarios": open with a named business-role actor, e.g. `Given a
catalog administrator`, never `Given I ...`) and 3 ("Single When per scenario": one action per
scenario — split a multi-action scenario instead of bundling steps). Those rules were written
for browser-test translation but apply to all Gherkin in this project; see
[errors-log.md](../errors-log.md) for the incident that made this cross-reference necessary.

```markdown
# [ID] Task title

## Description
Short functional description (2-4 lines).

## Type
frontend | backend | fullstack (related_task_id: ...) | includes database-expert: yes/no

## Gherkin
```gherkin
Feature: <name>

  Scenario: <main case>
    Given <context>
    When <action>
    Then <expected result>

  Scenario: <alternative/negative case>
    Given <context>
    When <action>
    Then <expected result>
```

## Files to create/modify
- `path/to/file.ext` — what changes and why
- (include a code snippet example if it adds clarity)

## Tests to perform
- [ ] Unit test: ...
- [ ] Integration test: ...
- [ ] Negative/edge case test: ...

## Expected outcome
What should be observable/working once done.

## Acceptance criteria
- [ ] Criterion 1
- [ ] Criterion 2

## Definition of Done
- [ ] Tests written and green
- [ ] Code reviewed (code-reviewer)
- [ ] No security findings (appsec-auditor)
- [ ] Documentation updated (docs-keeper)
- [ ] Acceptance criteria met
```

## Governance notes

- `docs-keeper` documents this workflow once and keeps it updated if the process changes.
- No agent advances a task to the next phase without leaving an explicit record of the
  reason (approval or rejection) in the task file.
- Returns between phases are loops: a task may go through TDD or security multiple times
  until it's green/clean before moving forward.

_Last updated: 2026-09-10 — Extended the link-integrity-check step with a new
[Regenerating the task-coordination files](task-files-links-and-ordering.md#regenerating-the-task-coordination-files)
subsection: a task file being created in `ai-spec/tasks/`, moved to `in-progress/`/`done/`, or
having its own "Dependencies" section edited now also requires `docs-keeper` to regenerate
`ai-spec/tasks-map.md` and `ai-spec/tasks-status.json` in the same pass, with a per-event
breakdown of what "update" means and the rule to never silently drop a live `"claimed"` entry.
Added a matching pointer from Phase 1's "Output of phase 1" line. Matching trigger conditions
added to the `docs-maintainer` skill and the `docs-keeper` agent; `docs/README.md`'s Workflow
entry now points at the two `ai-spec/` files. Requested directly by the project owner after the
two files drifted stale within one session (a closed task and a new claim, neither reflected
back into them) with no process keeping them current. Prior footer content (Phase 7's PR-based
closure step, 2026-09-09) is unchanged by this pass; see git history for it._
