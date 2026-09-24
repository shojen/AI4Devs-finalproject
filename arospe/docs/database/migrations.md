# Migration Conventions

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [File naming, structure and alterations](migrations/basics-and-alterations.md) | you write any migration: naming, `down()` symmetry, adding a column, backfilling a wrong default, dropping a unique index. | File naming; Structure; Real examples; Adding a column to an existing table |
| [UUID primary keys and FK indexes](migrations/uuid-primary-keys.md) | you create a UUID-keyed table or an FK: `foreignUuid`, explicit table names, no hand-written FK index, identifier length limits. | UUID primary keys |
| [Delete behaviour and vendored migrations](migrations/delete-behaviour-and-vendored.md) | you choose cascade/restrict/null for an FK, or touch a package-vendored or published-stub migration. | The three-way delete-behaviour rule: cascade, restrict, or null; Package-vendored migrations |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
