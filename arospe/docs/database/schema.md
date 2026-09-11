# Database Schema

## Table of Contents

- [ER diagram](#er-diagram)
- [Domain tables](#domain-tables)
- [Notes](#notes)

## ER diagram

Connection: `mysql` (`DB_CONNECTION=mysql` in `.env`, served by the `mysql:8.4` container in [`compose.yaml`](../../compose.yaml)). Only tables that carry a meaningful relationship are diagrammed; purely infrastructural tables (`cache`, `jobs`, `password_reset_tokens`) are listed in [Infrastructure tables](schema-users-auth.md#infrastructure-tables) instead, since they have no foreign keys.

```mermaid
erDiagram
    USERS ||--o{ PASSKEYS : owns
    USERS ||--o{ SESSIONS : has
    USERS ||--o{ MODEL_HAS_ROLES : "assigned via (polymorphic)"
    USERS ||--o{ MODEL_HAS_PERMISSIONS : "assigned via (polymorphic)"
    USERS ||--o{ NOTIFICATIONS : "notifiable (polymorphic)"
    MODEL_HAS_ROLES }o--|| ROLES : role_id
    MODEL_HAS_PERMISSIONS }o--|| PERMISSIONS : permission_id
    ROLE_HAS_PERMISSIONS }o--|| ROLES : role_id
    ROLE_HAS_PERMISSIONS }o--|| PERMISSIONS : permission_id
    SALES_REGIONS ||--o{ SALES_REGIONS : "parent_id (fiscal territory of)"
    USERS ||--o{ MEDIA : "uploaded_by (nullable)"
    PRODUCT_CATEGORIES ||--o{ PRODUCTS : product_category_id
    MEDIA ||--o{ PRODUCTS : "featured_media_id (nullable)"
    PRODUCT_MEDIA }o--|| PRODUCTS : product_id
    PRODUCT_MEDIA }o--|| MEDIA : media_id
    PRODUCT_SALES_REGION }o--|| PRODUCTS : product_id
    PRODUCT_SALES_REGION }o--|| SALES_REGIONS : sales_region_id
    PRODUCT_ATTRIBUTE_TYPES ||--o{ PRODUCT_ATTRIBUTE_VALUES : product_attribute_type_id
    PRODUCTS ||--o{ PRODUCT_VARIANTS : product_id
    MEDIA ||--o{ PRODUCT_VARIANTS : "featured_media_id (nullable)"
    PRODUCT_VARIANTS ||--o{ PRODUCT_VARIANT_VALUES : product_variant_id
    PRODUCT_ATTRIBUTE_VALUES ||--o{ PRODUCT_VARIANT_VALUES : product_attribute_value_id
    GEOGRAPHY_ENTRIES ||--o{ GEOGRAPHY_ENTRIES : "parent_id (nested under)"
    SHIPPING_ZONES ||--o{ SHIPPING_ZONE_GEOGRAPHY_ENTRY : shipping_zone_id
    GEOGRAPHY_ENTRIES ||--o{ SHIPPING_ZONE_GEOGRAPHY_ENTRY : geography_entry_id
    SHIPPING_CARRIERS ||--o{ SHIPPING_RATES : shipping_carrier_id
    SHIPPING_ZONES ||--o{ SHIPPING_RATES : shipping_zone_id

    USERS {
        uuid id PK
        string name
        string email UK
        string pending_email UK
        timestamp email_verified_at
        string status
        string password
        text two_factor_secret
        text two_factor_recovery_codes
        timestamp two_factor_confirmed_at
        string remember_token
        timestamp deleted_at
    }
    PASSKEYS {
        bigint id PK
        uuid user_id FK
        string name
        string credential_id UK
        json credential
        timestamp last_used_at
    }
    SESSIONS {
        string id PK
        uuid user_id FK
        string ip_address
        text user_agent
        longtext payload
        int last_activity
    }
    ROLES {
        bigint id PK
        string name
        string guard_name
    }
    PERMISSIONS {
        bigint id PK
        string name
        string guard_name
    }
    MODEL_HAS_ROLES {
        bigint role_id FK
        string model_type
        uuid model_uuid
    }
    MODEL_HAS_PERMISSIONS {
        bigint permission_id FK
        string model_type
        uuid model_uuid
    }
    ROLE_HAS_PERMISSIONS {
        bigint permission_id FK
        bigint role_id FK
    }
    SALES_REGIONS {
        uuid id PK
        string slug UK
        string code
        string name
        string description
        decimal rate
        string kind
        uuid parent_id FK
        boolean is_default
        boolean is_active
        smallint sort_order
    }
    MEDIA {
        uuid id PK
        string title
        text description
        string path UK
        string webp_path
        string avif_path
        smallint width
        smallint height
        int size_bytes
        uuid uploaded_by FK
    }
    PRODUCT_CATEGORIES {
        uuid id PK
        string name UK
    }
    PRODUCTS {
        uuid id PK
        uuid product_category_id FK
        string name
        string sku UK
        string type
        string status
        decimal price
        int stock
        text description
        uuid featured_media_id FK
    }
    PRODUCT_MEDIA {
        uuid product_id FK
        uuid media_id FK
        int position
    }
    PRODUCT_SALES_REGION {
        uuid product_id FK
        uuid sales_region_id FK
    }
    PRODUCT_ATTRIBUTE_TYPES {
        uuid id PK
        string name UK
        int position
    }
    PRODUCT_ATTRIBUTE_VALUES {
        uuid id PK
        uuid product_attribute_type_id FK
        string value
        int position
    }
    PRODUCT_VARIANTS {
        uuid id PK
        uuid product_id FK
        char combination_hash
        string sku UK
        decimal price
        int stock
        uuid featured_media_id FK
        int position
    }
    PRODUCT_VARIANT_VALUES {
        uuid product_variant_id FK
        uuid product_attribute_value_id FK
    }
    GEOGRAPHY_ENTRIES {
        bigint id PK
        string level
        bigint parent_id FK
        string name
        string normalized_name
        string ine_code UK
        string iso_alpha2 UK
        string province_name
    }
    SHIPPING_ZONES {
        uuid id PK
        string name UK
    }
    SHIPPING_ZONE_GEOGRAPHY_ENTRY {
        uuid shipping_zone_id FK
        bigint geography_entry_id FK
    }
    SHIPPING_CARRIERS {
        uuid id PK
        string code UK
        string name
        string description
        boolean is_active
    }
    SHIPPING_RATES {
        uuid id PK
        string name
        uuid shipping_carrier_id FK
        uuid shipping_zone_id FK
        decimal min_weight_kg
        decimal max_weight_kg
        decimal price
        string delivery_estimate
    }
    NOTIFICATIONS {
        uuid id PK
        string type
        string notifiable_type
        uuid notifiable_id
        text data
        timestamp read_at
    }
```

> The `model_has_roles` / `model_has_permissions` relationships to `USERS` are **polymorphic** (`model_type` + `model_uuid`, from `spatie/laravel-permission`) — `User` is the only morphable model in the codebase today. The morph key column is `model_uuid` (UUID-typed), renamed from the package default `model_id` (bigint) when `users.id` became a UUID — see [architecture/authorization.md](../architecture/authorization.md), which is also where the seeded roles, the permission catalog and how they are checked are documented.

## Domain tables

Split by domain into separate files, per [contracts.md](../contracts.md#doc-growth-management-rule)'s doc growth management rule — this file had grown past the 150k-character size this project treats as a hard limit. **Read only the table anchor your task actually needs, not the whole domain file** — this is the [Token-Efficient Reading and Dispatch Rule](../contracts.md#token-efficient-reading-and-dispatch-rule) applied at table granularity: the ER diagram above already tells you which columns/relationships a table has, so open a domain file only to read the *prose* around one specific table (invariants, derived columns, why a column is nullable, etc.).

- **[Users & Auth](schema-users-auth.md)** — read if the task touches [`users`](schema-users-auth.md#users), [`passkeys`](schema-users-auth.md#passkeys), the `spatie/laravel-permission` tables ([`roles`/`permissions`/`model_has_roles`/`model_has_permissions`/`role_has_permissions`](schema-users-auth.md#roles-permissions-model_has_roles-model_has_permissions-role_has_permissions)), or the [infrastructure tables](schema-users-auth.md#infrastructure-tables) tied to authentication (`password_reset_tokens`, `sessions`, `cache`, `jobs`).
- **[Products & Taxes](schema-products.md)** — read if the task touches [`sales_regions`](schema-products.md#sales_regions), [`media`](schema-products.md#media), [`product_categories`](schema-products.md#product_categories), [`products`](schema-products.md#products), [`product_media`](schema-products.md#product_media), [`product_sales_region`](schema-products.md#product_sales_region), [`product_attribute_types`](schema-products.md#product_attribute_types), [`product_attribute_values`](schema-products.md#product_attribute_values), [`product_variants`](schema-products.md#product_variants), or [`product_variant_values`](schema-products.md#product_variant_values). Example: a task about products only needs the [`products`](schema-products.md#products) anchor (plus [`product_media`](schema-products.md#product_media)/[`product_sales_region`](schema-products.md#product_sales_region) if it also touches the gallery or region assignment) — not the rest of the file.
- **[Shipping](schema-shipping.md)** — read if the task touches [`geography_entries`](schema-shipping.md#geography_entries) (the shipping geography catalog, physically independent of `sales_regions`), [`shipping_zones`](schema-shipping.md#shipping_zones), [`shipping_zone_geography_entry`](schema-shipping.md#shipping_zone_geography_entry), [`shipping_carriers`](schema-shipping.md#shipping_carriers), or [`shipping_rates`](schema-shipping.md#shipping_rates).
- **[Payment Methods, Customers & Notifications](schema-other.md)** — read if the task touches [`payment_methods`](schema-other.md#payment_methods), [`customers`](schema-other.md#customers), or [`notifications`](schema-other.md#notifications).

## Notes

- `app/Models/` holds fifteen classes: `User` (Epic 1); thirteen Epic 2/3 domain models — `SalesRegion`, `Media`, `ProductCategory`, `Product`, `ProductAttributeType`, `ProductAttributeValue`, `ProductVariant`, `GeographyEntry` (the only `bigint`-PK model in this app), `ShippingZone`, `ShippingCarrier`, `ShippingRate`, `PaymentMethod`, `Customer`; and `Role`, a `spatie/laravel-permission` subclass over the package's own `roles` table — no column, no migration of its own (see [architecture/authorization.md](../architecture/authorization.md#the-super-admin-roles-invariants)). **Four pivot tables have no model class at all** — `product_media`, `product_sales_region`, `product_variant_values`, `shipping_zone_geography_entry` — reached only through the owning models' `BelongsToMany`, the same shape the vendored `role_has_permissions`/`model_has_roles` pivots use.
- For migration authoring conventions (naming, `down()` requirements, real examples), see [database/migrations.md](migrations.md).
- **UUID (v7) primary keys.** Each table's PK type (`uuid` vs `bigint`) is already visible directly in the ER diagram above, and each per-domain schema file states its own table's status against [ADR 0001](../decisions/0001-uuid-primary-keys.md) at the point that table is documented — so this section no longer restates a consolidated status list. The ADR is the single source of truth for the policy and its full history: which entities it covers, the one named `bigint` exception (`geography_entries`), and every amendment since. The model-side convention (`HasUuids`, `@property string $id`, no restated `$keyType`/`$incrementing`) is in [conventions/base-standards.md](../conventions/base-standards.md#uuid-primary-keys); the migration-side pattern is in [database/migrations.md](migrations.md#uuid-primary-keys).

_Last updated: 2026-09-11 — Ad-hoc doc trim, not a story. Two changes. **(1)** The ER diagram above already states every table's PK type explicitly (`uuid` vs `bigint`), and every table's individual ADR-0001 status is independently documented at its own definition in `schema-products.md`/`schema-shipping.md`/`schema-other.md`/`schema-users-auth.md` — so the **Notes** section's consolidated "Done / Still future / Beyond the original seven / named `bigint` exception" status list was pure duplication and is now a short pointer at [ADR 0001](../decisions/0001-uuid-primary-keys.md) instead. Also condensed the model-class inventory bullet, dropping the accumulated per-model "first to..." narrative while keeping every model name, its table, and its one distinguishing fact (`GeographyEntry` as the only `bigint`-PK model; the four pivots with no model class). **(2)** The **Domain tables** section's per-domain bullets now link each individual table to its own anchor in its domain file, framed as conditional reading ("read if the task touches `<table>`") per the [Token-Efficient Reading and Dispatch Rule](../contracts.md#token-efficient-reading-and-dispatch-rule) — a task about `products` opens `schema-products.md#products`, not the whole file. No table, column, index, ER-diagram fact, or ADR status claim was changed in either case — only restated more directly, per [contracts.md](../contracts.md#doc-growth-management-rule)'s doc growth management rule.

_Previously: 2026-09-11 — Split this file into per-domain schema files (`database/schema-users-auth.md`, `database/schema-products.md`, `database/schema-shipping.md`, `database/schema-other.md`), per [contracts.md](../contracts.md#doc-growth-management-rule)'s doc growth management rule — this file had grown past the 150k-character size this project treats as a hard limit. This file's longer prior `_Previously:` chain is folded into this single line — no content changed or lost; see git history for the full prior chain if needed._
