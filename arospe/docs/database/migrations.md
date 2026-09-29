# Migration Conventions

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [File naming, structure and alterations](migrations/basics-and-alterations.md) | you write any migration: naming, `down()` symmetry, adding a column, backfilling a wrong default, dropping a unique index, or removing a source-of-truth column after backfilling it into another table. | File naming; Structure; Real examples; Adding a column to an existing table; Removing a source-of-truth column after backfilling it elsewhere |
| [UUID primary keys and FK indexes](migrations/uuid-primary-keys.md) | you create a UUID-keyed table or an FK: `foreignUuid`, explicit table names, no hand-written FK index, identifier length limits. | UUID primary keys |
| [Delete behaviour and vendored migrations](migrations/delete-behaviour-and-vendored.md) | you choose cascade/restrict/null for an FK, or touch a package-vendored or published-stub migration. | The three-way delete-behaviour rule: cascade, restrict, or null; Package-vendored migrations |

_Last updated: 2026-09-29 — Story 0070 (Translatable content mechanism — backend, piloted on Product Categories). [File naming, structure and alterations](migrations/basics-and-alterations.md) gained "Removing a source-of-truth column after backfilling it elsewhere" — this repo's first migration pair that drops a populated, shipped column after backfilling its data into a child table, and the first knowingly non-inverse `down()`._
