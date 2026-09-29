---
name: watch-ci
description: "Use this skill to watch this repo's GitHub Actions pipeline for the current branch and, when it's red, diagnose the failure following docs/contracts.md's CI / GitHub Actions Review Protocol. Trigger when the user asks to watch, check, or monitor CI/the pipeline/the build, asks whether the pipeline is green, or wants CI watching to happen as part of pushing. Invoke with no argument to check the current state of the most recent runs on this branch; invoke with `after-push` immediately after a `git push` to watch the run it just triggered until it finishes. This skill itself only watches and diagnoses — pushing a fix and re-invoking this skill are ordinary next steps taken outside it, per docs/contracts.md's Commit Practice Rule and CI Review Protocol."
license: MIT
---

# Watch CI

Watches this repository's GitHub Actions runs for the current branch and, on a red run,
diagnoses the real cause by following [`docs/contracts.md`](../../../docs/contracts.md)'s
**CI / GitHub Actions Review Protocol** — the binding source of truth for *how* to investigate.
This skill is the reusable, on-demand entry point into that protocol; it does not restate or
own the protocol itself, only invokes it.

## Usage

```
/watch-ci             # check the current state of the last few runs on this branch
/watch-ci after-push  # watch the run just triggered by a push, to completion
```

## Scope: this skill diagnoses — it does not push, rerun, or commit itself

This skill's job is to watch and diagnose. It never runs `git push`, `gh run rerun`,
`gh workflow run`, or `git commit` as part of executing — those are ordinary steps in this
project's workflow (per [`docs/contracts.md`](../../../docs/contracts.md)'s Commit Practice
Rule and CI Review Protocol), taken by the calling agent outside this skill. If diagnosing a
red run produces a fix, fix and commit the real cause, push it, and re-invoke
`/watch-ci after-push` to watch the run that push triggers.

## Program

```sudolang
WatchCI {
  State {
    Branch: current git branch (git branch --show-current)
    Mode: "check" (no argument) | "after-push" (argument is "after-push")
  }

  Fn checkGhAuth() {
    run `gh auth status`
    If not authenticated:
      Stop and tell the user to run `gh auth login` (suggest the `!` prefix so it runs in
      this session) or export GH_TOKEN — do not attempt to work around it, do not schedule
      a loop that will just keep failing the same way.
  }

  Fn identifyRun() {
    If Mode == "after-push":
      # a push was just made — the run of interest is the newest one for this branch,
      # which may take a few seconds to appear after the push completes
      poll `gh run list --branch $Branch --limit 1` (a few seconds apart, bounded — this is
        waiting for a run to *appear*, not for it to finish) until a run shows up whose
        creation time is after the push, or a short bound (e.g. ~1 minute) is reached
      If none appears: report that no run was triggered (check whether the push actually
        touched a path the workflow triggers on) and stop — do not guess at an older run.
    Else:
      list the 5 most recent runs: `gh run list --branch $Branch --limit 5`
  }

  Fn watchToCompletion(runId) {
    # blocks in this session until the run finishes; prefer this over a timed poll loop,
    # since it reacts to the actual event instead of guessing an interval
    run `gh run watch <runId> --exit-status`
  }

  Fn diagnoseIfRed(runId) {
    # this delegates entirely to docs/contracts.md's own protocol — do not re-derive or
    # shortcut it here
    Follow docs/contracts.md → "CI / GitHub Actions Review Protocol", starting from its
    step 2 (view only the failed step's log) since step 1 (list runs) is already done:
      1. `gh run view <runId> --log-failed`
      2. Reproduce locally with this project's REAL commands (never a generic equivalent):
         - `php artisan test --compact --filter=<TestName>` for a failing test, then the
           full unscoped `php artisan test` per the Full Test Suite Gate Rule
         - `vendor/bin/pint --format agent` (unscoped) for a formatting/style failure
         - `composer types:check` for a Larastan/static-analysis failure
      3. Fix the real cause, not the test — unless the test itself asserts something wrong
         (same Test-issue vs Code-issue distinction as docs/workflow.md Phase 3)
      4. Stage and commit the fix (Commit Practice Rule) — this skill itself doesn't push;
         push the fix and re-invoke `/watch-ci after-push` as the next ordinary step
  }

  Main {
    checkGhAuth()
    identifyRun()

    Match Mode:
      "after-push" ->
        report which run was found, then watchToCompletion(runId)
        if conclusion is failure: diagnoseIfRed(runId)
        else: report green briefly, stop — no further polling
      "check" ->
        if the most recent run(s) are still in progress, ask the user (or infer from context)
          whether to watch it to completion now (watchToCompletion) or just report current
          status and stop — don't silently block on a long-running run nobody asked to wait for
        if the most recent run is red: diagnoseIfRed(runId)
        if all recent runs are green: report that plainly and stop
  }
}
```

## Notes

- This skill deliberately does **not** schedule a fixed-duration timed loop (e.g. "poll every
  5 minutes for 20 minutes") — watching reacts to the real event (a run existing, a run
  finishing) via `gh run watch`, which blocks until the run concludes, rather than guessing at
  a polling interval disconnected from whether anything is actually running.
- `gh run watch` needs a run id. If the id isn't already known (e.g. resumed from a prior turn),
  re-run `identifyRun()` rather than assuming which run is still the right one.
- Every fact about what CI actually checks and how to run it locally lives in
  [`docs/testing/ci/pipeline-integration.md`](../../../docs/testing/ci/pipeline-integration.md)
  and [`docs/testing/ci/commands.md`](../../../docs/testing/ci/commands.md) — read those instead
  of assuming a command, if the failure is in an area this skill's own protocol summary doesn't
  cover in enough detail.
