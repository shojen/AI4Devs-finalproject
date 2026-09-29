# Browser Test Setup (Pest 4, Playwright-driven)

This file is named `playwright-setup.md` for discoverability, but this project does **not** run a standalone Playwright / `playwright-bdd` / Cucumber toolchain. Browser tests are written with **Pest 4's built-in browser testing**, which drives a real browser via Playwright under the hood. This keeps browser tests inside the existing Pest suite (same `make:test`, same factories, same Laravel helpers) instead of introducing a parallel JavaScript test runner. See [README.md](README.md#tooling-decision-read-this-first) for the decision.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [Status, folder structure and syntax](playwright-setup/status-structure-and-syntax.md) | you set up or locate browser tests: install status, the `tests/Browser/` layout, and real Pest browser syntax. | Current status: installed; Folder structure; Real syntax |
| [Waiting rules and environment findings](playwright-setup/waiting-rules.md) | a browser test hangs or flakes: banned/accepted waiting patterns, the `->wait()` ceiling, upload limits, duplicate `data-test` hooks, orphaned Playwright processes, DOM occlusion. | Waiting: one call is banned in this repo, and one is bounded |
| [Selectors, tagging, CI and examples](playwright-setup/selectors-tagging-and-ci.md) | you choose selectors, tag/name/parallelize browser tests, or check how CI runs them. | Selector strategy; Test tagging, naming, and parallelization; CI integration; Correct vs. incorrect examples |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
