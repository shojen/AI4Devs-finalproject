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

Split by domain into separate files, per [contracts.md](../contracts.md#doc-growth-management-rule)'s doc growth management rule — this file had grown past the 150k-character size this project treats as a hard limit:

- **[Users & Auth](schema-users-auth.md)** — `users`, `passkeys`, `roles`/`permissions`/`model_has_roles`/`model_has_permissions`/`role_has_permissions`, and the infrastructure tables tied to authentication (`password_reset_tokens`, `sessions`, `cache`, `jobs`).
- **[Products & Taxes](schema-products.md)** — `sales_regions`, `media`, `product_categories`, `products`, `product_media`, `product_sales_region`, `product_attribute_types`, `product_attribute_values`, `product_variants`, `product_variant_values`.
- **[Shipping](schema-shipping.md)** — `geography_entries` (the shipping geography catalog, physically independent of `sales_regions`), `shipping_zones`, `shipping_zone_geography_entry`, `shipping_carriers`, `shipping_rates`.
- **[Payment Methods, Customers & Notifications](schema-other.md)** — `payment_methods`, `customers`, `notifications`.

## Notes

- `app/Models/` holds fifteen classes, three different kinds of thing: `User` (Epic 1's domain model); the Epic 2/3 domain models `SalesRegion` (task 0016, the first table this repo created greenfield with a UUID PK), `Media` (story 0019), `ProductCategory` (story 0023 — no relationships at all until `products.product_category_id` gave it one), `Product` (story 0024, the first with a required FK into another Epic 2 table), `ProductAttributeType` + `ProductAttributeValue` (story 0028, the first pair related to each other by a plain FK rather than a pivot), `ProductVariant` (story 0029, the root of the variant combination), `GeographyEntry` (story 0032, the only `bigint`-PK model in this app), `ShippingZone` (story 0033), `ShippingCarrier` (story 0035, a standalone catalog with no relationships), `ShippingRate` (story 0036, the first model with FKs into two other tables at once), `PaymentMethod` (story 0038, another standalone catalog), and `Customer` (story 0041, Epic 3's first domain model, no FK of any kind per D-8); and `Role`, a `spatie/laravel-permission` subclass over the package's existing `roles` table rather than a new entity — it adds no column and no migration (see [architecture/authorization.md](../architecture/authorization.md#the-super-admin-roles-invariants)). This file grows a new section per table as the domain layer is built. **Four pivot tables have no model class of their own** — `product_media`, `product_sales_region`, `product_variant_values` and `shipping_zone_geography_entry` — each reached only through the owning models' `BelongsToMany` (`Product::gallery()`/`salesRegions()`, `ProductVariant::values()`/`ProductAttributeValue::variants()`, `ShippingZone::geographyEntries()`), the same shape the vendored `role_has_permissions`/`model_has_roles` pivots use; `shipping_zone_geography_entry` deliberately gains no inverse `GeographyEntry::shippingZones()` relation (D-11).
- For migration authoring conventions (naming, `down()` requirements, real examples), see [database/migrations.md](migrations.md).
- **UUID (v7) primary keys ([ADR 0001](../decisions/0001-uuid-primary-keys.md)).** Status is split:
  - **Done:** `users.id` is a UUID (v7) `CHAR(36)` PK (Epic 1), applied by the 5 alteration migrations `2026_07_22_100001..100005_*.php`. The cascade is complete: `passkeys.user_id`, `sessions.user_id`, and the `spatie/laravel-permission` `model_has_roles` / `model_has_permissions` morph key (renamed `model_id` → `model_uuid`, retyped to `uuid`) all match. The ER diagram and tables above reflect this real, current state.
  - **Still future:** three of ADR 0001's original six not-yet-implemented entities (blog categories, blog tags, blog posts — PRD Epic 4, see [../PRD/PRD.md](../PRD/PRD.md)) do not exist in code yet. They will be created with UUID PKs from the start — greenfield, with no migration complexity. **Product Categories, Products and Product Variants are the first three of the six to land** — see [`product_categories`](schema-products.md#product_categories) (story 0023), [`products`](schema-products.md#products) (story 0024) and [`product_variants`](schema-products.md#product_variants) (story 0029) — and none needed an ADR amendment, since all three are among the ADR's own original seven named entities rather than an addition like `sales_regions`/`media` below; see [ADR 0001 Amendment 4](../decisions/0001-uuid-primary-keys.md#amendment-4-2026-09-04--product-variants-is-the-third-of-the-original-seven-to-ship).
  - **`product_media`, `product_sales_region`, `product_variant_values` and `shipping_zone_geography_entry` are not counted against either list.** All four are pivot tables with no surrogate primary key of their own — composite `(product_id, media_id)` / `(product_id, sales_region_id)` / `(product_variant_id, product_attribute_value_id)` / `(shipping_zone_id, geography_entry_id)` over already-existing FKs — the same shape as the vendored `spatie/laravel-permission` pivots (`role_has_permissions`, `model_has_roles`), which this ADR has never covered either. There is no "entity identifier" here for the ADR's policy to apply to. `shipping_zone_geography_entry` is the first of the four whose two FKs are not both UUID (one side is `geography_entries.id`, this schema's one `bigint` exception below) — that mixed key crossing is a fact about the columns it references, not a new case this ADR's own policy needs to address.
  - **Beyond ADR 0001's original seven — nine tables now, and the ADR's own policy already covers it with no further amendment needed.** `sales_regions` (task 0016), `media` (story 0019), `product_attribute_types` + `product_attribute_values` (story 0028), `shipping_zones` (story 0033), `shipping_carriers` (story 0035), `shipping_rates` (story 0036), `payment_methods` (story 0038) and `customers` (story 0041) are all UUID (v7) PK tables the ADR's Context section does not name. [ADR 0001 Amendment 1](../decisions/0001-uuid-primary-keys.md#amendment-1-2026-08-27--the-scope-is-the-policy-not-the-list-of-seven) states the project-wide policy — every new business entity is UUIDv7, with a high-volume internal geography lookup table as the one named `bigint` exception — precisely so a later addition needs no further amendment; `payment_methods` is the one instance on record where a Three Amigos debate recommended `bigint` and an explicit user decision overrode it toward the standing policy instead (see [`payment_methods`](schema-other.md#payment_methods) above). `product_attribute_types`/`product_attribute_values` fit the ADR's enumeration-safety rationale directly (ids queried and rendered in a 10¹–10² admin-defined list, `media`'s own shape); `shipping_zones`/`shipping_carriers`/`shipping_rates`/`payment_methods`/`customers` fit it too, at the same 10¹–10⁴ order of magnitude — unlike `sales_regions`, which is keyed this way for **consistency** rather than because the rationale applies (a fixed public country catalog has nothing to enumerate, and a mixed-PK domain is worse than an over-provisioned key on ~254 rows).
  - **`geography_entries` (story 0032) is the named `bigint` exception Amendment 1 predicted, now real** — see [ADR 0001's Amendment 5](../decisions/0001-uuid-primary-keys.md#amendment-5-2026-09-06--the-named-bigint-exception-is-real-geography_entries). It is not counted against either list above: it was never one of the ADR's original seven named entities, and it is deliberately the one table this ADR's UUIDv7 policy does not apply to.
  - The model-side convention (`HasUuids`, `@property string $id`) is in [conventions/base-standards.md](../conventions/base-standards.md#uuid-primary-keys); the migration-side pattern is in [database/migrations.md](migrations.md#uuid-primary-keys).

_Last updated: 2026-09-11 — Split this file into per-domain schema files (`database/schema-users-auth.md`, `database/schema-products.md`, `database/schema-shipping.md`, `database/schema-other.md`), per [contracts.md](../contracts.md#doc-growth-management-rule)'s doc growth management rule — this file had grown past the 150k-character size this project treats as a hard limit. This file is now the index: the full ER diagram (kept whole, in one place) and this **Notes** section. Every table's heading and anchor moved unchanged into its new file, and every existing cross-reference by anchor — from `docs/`, `ai-spec/tasks/` and `app/` comments — was repointed at the new filename rather than left dangling. No table, column, index, ER-diagram fact or UUID/ADR-0001 status claim was changed.

_Previously: 2026-09-11 — Doc growth management pass (docs/contracts.md#doc-growth-management-rule), not a story. Condensed the **Notes** section's fifteen-model list and the "Beyond ADR 0001's original seven" bullet, removing accumulated ordinal-counting narrative while keeping every model name, table name and distinguishing fact. Folded the prior `_Previously:` line (story 0043/0042 reconciliation) into this single line per [contracts.md](../contracts.md#doc-growth-management-rule) — no content changed or lost; see git history for the full prior chain if needed._
