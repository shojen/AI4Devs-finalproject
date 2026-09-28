# Database Schema — Shipping

Part of [Database Schema](schema.md) — see [schema.md](schema.md#er-diagram) for the full ER diagram and [schema.md#notes](schema.md#notes) for the UUID/ADR-0001 status notes. This file covers the Shipping domain: `geography_entries` (the shipping geography catalog, physically independent of `sales_regions`), `shipping_zones`, `shipping_zone_geography_entry`, `shipping_carriers`, and `shipping_rates`.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [geography_entries](schema-shipping/geography-entries.md) | the task touches the shipping geography catalog (countries, communities, municipalities). | `geography_entries` |
| [shipping_zones and shipping_zone_geography_entry](schema-shipping/zones.md) | the task touches shipping zones or the zone-to-geography pivot. | `shipping_zones`; `shipping_zone_geography_entry` |
| [shipping_carriers and shipping_rates](schema-shipping/carriers-and-rates.md) | the task touches carriers or rate rules (weight brackets, zone/carrier FKs). | `shipping_carriers`; `shipping_rates` |

_Last updated: 2026-09-29 — Story 0070 (Translatable content mechanism — backend, piloted on Product Categories). [geography_entries](schema-shipping/geography-entries.md), [shipping_zones and shipping_zone_geography_entry](schema-shipping/zones.md) and [shipping_carriers and shipping_rates](schema-shipping/carriers-and-rates.md) each had one stale `product_categories.name` precedent citation repointed to `product_category_translations.name`, since story 0070 moved that column — no content beyond the citation changed._
