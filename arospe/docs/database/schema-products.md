# Database Schema — Products & Taxes

Part of [Database Schema](schema.md) — see [schema.md](schema.md#er-diagram) for the full ER diagram and [schema.md#notes](schema.md#notes) for the UUID/ADR-0001 status notes. This file covers the Products/Taxes domain: `sales_regions`, `product_categories`, `products`, `product_media`, `product_sales_region`, `product_attribute_types`, `product_attribute_values`, `product_variants`, and `product_variant_values`.

## Table of Contents

This document is split into parts so an agent reads only the one its task touches. Open the part whose *Read when* column matches; every part carries the original text unchanged, and every heading keeps its original anchor name (only the file changed).

| Part | Read when | Sections |
| --- | --- | --- |
| [sales_regions and media](schema-products/sales-regions-and-media.md) | the task touches Sales Regions/tax rates or the Media Library table. | `sales_regions`; `media` |
| [product_categories and products](schema-products/categories-and-products.md) | the task touches product categories or the `products` table (status, price, stock, derived columns). | `product_categories`; `products` |
| [product_media and product_sales_region](schema-products/gallery-and-region-pivots.md) | the task touches a product's gallery/featured image or its Sales Region assignment pivot. | `product_media`; `product_sales_region` |
| [product_attribute_types and product_attribute_values](schema-products/attribute-types-and-values.md) | the task touches product attribute types or their values (id stability, unique keys). | `product_attribute_types`; `product_attribute_values` |
| [product_variants and product_variant_values](schema-products/variants.md) | the task touches product variants: `combination_hash`, derived `sku`, the variant-value pivot, and their re-derivation triggers. | `product_variants`; `product_variant_values` |

_Last updated: 2026-09-24 — split into the parts above (docs optimization pass); no content changed. The prior revision-history footer, if any, stays at the end of the last part._
