# Arospe PRD — Epic 2 — Products, Taxes & Sales Regions, Shipping

> Part of [Arospe PRD](../PRD.md). **Read this part when:** the task is a Products, categories, attributes, variants, Sales Regions/taxes, media, shipping or payment-methods story. The other parts are listed in the [hub](../PRD.md#table-of-contents).

## Epic 2 — Products, Taxes & Sales Regions, Shipping

**Priority: 2 (the store core).** The commerce data a future storefront runs on. Split into
three closely related areas below.

### 2.1 Sales Regions & Taxes

This is the **biggest deliberate divergence from the prototype**. In the prototype, Taxes is a
flat, freely-created per-country list with no default flag and no region catalog. Here, a **tax
rule *is* a Sales Region entry**: the region catalog is the single source of truth, each entry
carries its own rate/description/code, exactly one entry is flagged default, and the catalog is
**seeded and fixed** (admins configure existing entries, they don't invent countries). Every
entry is an **individual country**, or one of Spain's five fiscal territories — the catalog has
**no supranational or catch-all grouping entries**, per
[assumption 6](foundations.md#assumptions--confirmed-decisions).

![Tax rates list (prototype)](../images/09-impuestos-lista.png)
*Prototype Taxes list: country/region code chip, name, description, and rate %, with edit/delete
per row. **This PRD extends it**: the flat editable country list becomes the seeded Sales
Region catalog, gains a single "default" flag, and adds Spain's special fiscal territories
(Península, Baleares, Canarias, Ceuta, Melilla) as distinct entries.*

![Tax rate modal (prototype)](../images/10-impuestos-modal.png)
*Prototype create/edit tax modal: País/Región, Código, Descripción, Tasa (%). **This PRD
extends it**: the entry is chosen from the seeded catalog rather than typed free-form, and the
modal edits the rate/description/status of an existing region entry.*

```gherkin
Feature: Sales Regions and their tax rates

  Scenario: Configure the tax rate on a seeded region entry
    Given a tax administrator, with the Sales Region catalog seeded with countries
      and Spain's fiscal territories (Península, Baleares, Canarias, Ceuta, Melilla)
    When they set the rate, description, and code on the "Canarias" entry
    Then the "Canarias" entry is saved with that rate and shown in the list

  Scenario: Marking a new default clears the previous one
    Given a tax administrator, with "España (Península)" flagged as the default entry
    When they mark "Francia" as the default
    Then "Francia" becomes the only default entry
    And "España (Península)" is no longer the default

  Scenario: Disabling the current default region is blocked unless a new default is set
    Given a tax administrator, with "España (Península)" as the current default entry
    When they try to disable/deactivate "España (Península)" without setting another default
    Then the action is blocked so the catalog never ends up with zero default entries
    And disabling it is only allowed when another entry is simultaneously set as the new default

  Scenario: The catalog does not allow inventing new countries
    Given a tax administrator viewing the seeded, fixed Sales Region catalog
    When they look for a way to add a brand-new country from scratch
    Then no such option exists, and only seeded entries can be configured or enabled/disabled

  Scenario: Spain exposes its fiscal sub-territories as separate entries
    Given a tax administrator viewing Spain in the Sales Region catalog
    When they expand Spain's entries
    Then Península, Baleares, Canarias, Ceuta, and Melilla appear as
      distinct, separately-configurable entries

  Scenario: The default rate applies when no region matches
    Given a tax administrator has flagged one region entry as the default
    And a product assigned to no region entry matching a given destination
    When the applicable tax rate for that destination is resolved
    Then the default entry's rate is used

  Scenario Outline: An invalid tax rate is rejected
    Given a tax administrator editing a region entry
    When they enter <invalid_rate> as the tax rate
    Then the change is rejected with a validation message

    Examples:
      | invalid_rate        |
      | a negative value    |
      | a non-numeric value |
```

**Acceptance criteria — Sales Regions & Taxes**

- [ ] The Sales Region catalog is seeded from the ISO country list plus Spain's five fiscal
      territories — individual entries only, **no grouping entries** — and lives as a section
      **inside the Taxes area** (not a top-level sidebar item).
- [ ] Each entry carries its own rate, description, and code; admins configure existing entries
      and can enable/disable them, but cannot create new countries from scratch.
- [ ] Exactly one entry is the default at all times; setting a new default clears the old one.
- [ ] The current default entry cannot be disabled/deactivated unless another entry is
      simultaneously set as the new default — the catalog never has zero defaults.
- [ ] Rate resolution for a product+address uses the matching region entry, falling back to the
      default when no match exists.
- [ ] Rate validation rejects negative/non-numeric values.
- [ ] Sales Regions (fiscal) and Shipping zones are kept as two independent catalogs.

### 2.2 Products

The prototype's list + editor pattern stays. **Extensions:** a managed product **category
taxonomy** (full CRUD, separate from blog), configurable **product variants**, and a required
**product type** — each product is either **physical** or **virtual** (digital). The product
type drives how an order resolves its tax Sales Region (see
[3.2 Orders](epic-3-customers-orders.md#32-orders)). Currency is EUR only; images come from the shared media gallery
documented in [2.3 Shared Media Gallery](#23-shared-media-gallery) (stored locally, in multiple
formats).

> **Technical note — UUID (v7) primary keys.** **Products, Product Variants, and Product
> Categories** each use a UUID (v7) primary key via Laravel's `HasUuids` trait (see
> [assumption 19](foundations.md#assumptions--confirmed-decisions)). All three are greenfield tables, created
> with the UUID PK from day one — no migration complexity beyond declaring it. Not yet
> implemented; this lands during Epic 2's TDD work.

![Products list](../images/04-productos-lista.png)
*Products list: thumbnail, name + SKU, price, color-coded stock (low / out-of-stock), and a
status badge (Activo / Borrador / Agotado), with a primary "Nuevo producto" action.*

![Product editor](../images/05-productos-editor.png)
*Product editor: name, SKU, category select, a WYSIWYG description (Bold/Italic/Underline/H2/
lists/link/Insert image), an image gallery strip, and a right-hand side panel for status, price
(€), stock, and the featured image. **Extends the prototype**: the category select is backed by a
managed taxonomy, and variants add per-combination SKU/price/stock/image.*

```gherkin
Feature: Product catalog

  Scenario: Create a product with core fields
    Given a catalog administrator
    When they create a product with a name, a unique SKU, a category, a product type
      (physical or virtual), an EUR price, stock, a status, a WYSIWYG description, and
      a featured image
    Then the product appears in the products list with its status badge

  Scenario Outline: A duplicate SKU is rejected
    Given a catalog administrator, with an existing product using SKU "RNR-001"
    When they try to save <record> with the SKU "RNR-001"
    Then saving is rejected with a validation message

    Examples:
      | record          |
      | another product |
      | a variant       |

  Scenario: Selecting Spain surfaces its fiscal sub-entries in the region picker
    Given a catalog administrator editing a product, with the Sales Region catalog seeded
    When they select "Spain" in the product's region picker
    Then Spain's fiscal sub-entries (Península, Baleares, Canarias, Ceuta, Melilla)
      are surfaced as selectable options

  Scenario: Assign a product to several sales regions
    Given a catalog administrator editing a product, with the Sales Region catalog seeded
    When they assign the product to Península, Canarias, and France
    Then the product is associated with all three selected regions

  Scenario: A product's tax uses its assigned region's rate
    Given a catalog administrator, with a product assigned to the "Canarias" region entry
    When the tax rate for that product in Canarias is resolved
    Then the "Canarias" entry's rate is used
```

```gherkin
Feature: Product categories (extends the prototype)

  Scenario: Create a product category
    Given a catalog administrator
    When they create a product category named "Footwear"
    Then it appears in the product editor's category selector

  Scenario: Rename a product category
    Given a catalog administrator, with a product category "Footwear"
    When they rename it to "Running shoes"
    Then the category is shown with its new name wherever it is used

  Scenario: Delete an unused product category
    Given a catalog administrator, with a product category "Footwear" assigned to no products
    When they delete "Footwear"
    Then it no longer appears in the product editor's category selector

  Scenario: Product categories are independent from blog categories
    Given a catalog administrator
    When they view the product category list
    Then it contains only product categories, separate from blog categories

  Scenario: Deleting a product category still in use is hard-blocked with a count
    Given a catalog administrator, with the category "Calzado" assigned to 12 products
    When they try to delete "Calzado"
    Then deletion is always blocked (no confirm-and-proceed path)
    And the message states how many products use it
      (e.g. "This category is used by 12 products and cannot be deleted")
    And they must reassign those products' category before it can be deleted
```

```gherkin
Feature: Product variants (extends the prototype)

  Scenario: Define a product attribute type with values
    Given a catalog administrator
    When they define an attribute type "Size" with the values 38, 39, and 40
    Then "Size" and its values are available when building variants

  Scenario: Create a variant as an attribute combination
    Given a catalog administrator, with a product having the attribute types Size and Color
    When they generate the variant "Size 40 / Color Black"
    Then that variant has its own SKU, price, and stock

  Scenario: A variant without its own image inherits the parent's featured image
    Given a catalog administrator, with a variant that has no featured image of its own
    When the variant is displayed
    Then it inherits the parent product's featured image

  Scenario: A variant with its own image uses that image
    Given a catalog administrator, with a variant that has its own featured image
    When the variant is displayed
    Then its own featured image is used instead of the parent's

  Scenario: A duplicate attribute combination is rejected
    Given a catalog administrator, with the variant "Size 40 / Color Black" already on a product
    When they try to add the same combination again
    Then it is rejected as a duplicate
```

**Acceptance criteria — Products**

- [ ] Products support name, unique SKU, category, **product type (physical or virtual)**, EUR
      price, stock, status, WYSIWYG description, featured image, and a multi-image gallery, per
      the prototype list+editor. Product type is required.
- [ ] Product categories have full CRUD and are independent from blog categories; a category in
      use **cannot** be deleted (hard block, no confirm-and-proceed) and the message states how
      many products use it, requiring reassignment first.
- [ ] Variant attribute types and values are admin-configurable (not hardcoded); each variant
      combination has its own SKU/price/stock and an optional image that inherits the parent's.
- [ ] Duplicate SKUs and duplicate variant combinations are rejected.
- [ ] Products are assignable to one or more Sales Regions via a searchable multi-select where
      selecting Spain surfaces its fiscal sub-entries.
- [ ] Product and variant images come from the shared media gallery (see
      [2.3 Shared Media Gallery](#23-shared-media-gallery)).
- [ ] Products, Product Variants, and Product Categories each use a UUID (v7) primary key, via
      Laravel's `HasUuids` trait, applied at both the migration and Eloquent model level.

### 2.3 Shared Media Gallery

The shared media gallery is a modal reused by **both** Products and Blog for choosing and
uploading images. Its behavior is taken directly from the prototype's `openGallery()` (in
[`docs/arospe-handoff/project/js/common.js`](../../arospe-handoff/project/js/common.js)) and the
screenshot below. It supports two selection modes — **single-select** (used for a featured
image or a single inline insertion) and **multi-select** (used to add several images to a
product/post gallery at once) — plus title/description search and two upload paths (file picker
and drag-and-drop). Per [assumption 11](foundations.md#assumptions--confirmed-decisions), every upload is
stored locally and generates `.webp` and `.avif` variants alongside the kept original.

![Shared media gallery](../images/06-productos-galeria.png)
*Shared media gallery modal (reused by Products and Blog): search by title/description, a
drag-and-drop dropzone plus a "Subir" file picker, and selectable tiles. The footer shows the
selection count and the insert/add action ("Añadir" in multi-select).*

```gherkin
Feature: Shared media gallery

  Scenario: Search filters the gallery by title or description
    Given a catalog administrator with the media gallery open
    When they search the gallery for a title or description keyword
    Then only images whose title or description match are shown

  Scenario: The gallery shows an empty state when a search matches nothing
    Given a catalog administrator with the media gallery open
    When they search the gallery for a keyword that matches no image
    Then a "no results" empty state is shown instead of tiles

  Scenario: Upload an image via the file picker
    Given a catalog administrator with the media gallery open
    When they choose an image file with the "Subir" file picker
    Then the image is added to the gallery as a selectable tile

  Scenario: Upload an image by drag-and-drop
    Given a catalog administrator with the media gallery open
    When they drop an image file onto the gallery dropzone
    Then the image is added to the gallery as a selectable tile

  Scenario: Uploading an image generates webp and avif variants
    Given a catalog administrator with the media gallery open
    When they upload a `.png` or `.jpg` image
    Then the original is kept and `.webp` and `.avif` variants are generated alongside it

  Scenario Outline: An invalid upload is rejected
    Given a catalog administrator with the media gallery open
    When they upload <invalid_file>
    Then the upload is rejected with an explanatory message

    Examples:
      | invalid_file                       |
      | a non-image file                   |
      | an image exceeding the size limit  |

  Scenario: Single-select mode stages exactly one image
    Given a catalog administrator picking a featured image in single-select mode
    When they select a second tile after already selecting one
    Then only the most recently selected image is staged for insertion

  Scenario: Multi-select mode stages several images at once
    Given a catalog administrator adding images in multi-select mode
    When they select several tiles and confirm with "Añadir"
    Then all selected images are staged and attached at once

  Scenario: Inserting an image inline from the WYSIWYG editor
    Given a blog editor with the WYSIWYG "insert image" action active
    When they insert a selected image from the gallery
    Then the image is placed inline in the description or body

  Scenario: Selecting an image in featured mode sets the featured image
    Given a catalog administrator choosing an image in featured mode
    When they use the selected image as the featured image
    Then it becomes the product's (or variant's) featured image
```

**Acceptance criteria — Shared Media Gallery**

- [ ] The gallery is a single shared component reused by both Products and Blog.
- [ ] It supports title/description search with an explicit empty state.
- [ ] It supports uploading via both a file picker and drag-and-drop onto a dropzone.
- [ ] Every uploaded image keeps its original `.png`/`.jpg`/`.jpeg` and additionally generates
      `.webp` and `.avif` variants; all are stored locally.
- [ ] Invalid uploads (non-image, over size limit) are rejected with a message.
- [ ] Single-select mode stages exactly one image; multi-select mode stages several at once.
- [ ] Featured mode sets the product's/variant's featured image; the editor "insert image"
      action places an image inline in the description/body.

### 2.4 Shipping

**Carriers and rate rules** match the prototype **almost as-is**: integrated carriers with an
enable/disable toggle, and per-carrier rate rules by shipping zone + weight range + price +
delivery estimate. **No carrier API integration** — configuration is manual.

The **shipping zone catalog** is the one part of this section that does *not* follow the
prototype: zones are **admin-created and fully editable**, built on top of a seeded geography
catalog, rather than a short fixed list of badges.

> **Deliberate divergence — the shipping zone catalog (decided 2026-08-17).** The prototype's zone
> badges (Península / Baleares / Canarias / Unión Europea…), and this document's own
> [assumption 12](foundations.md#assumptions--confirmed-decisions) ("shipping matches the prototype almost
> as-is"), read as though shipping zones were a small **fixed** list. They are not. Confirmed with
> the product owner during **Epic 2's Three Amigos Phase 0 decomposition on 2026-08-17**, the zone
> catalog became a **full admin-CRUD catalog** over a **seeded, fine-grained geography catalog**.
> This is the same kind of deliberate, documented extension that
> [assumption 5](foundations.md#assumptions--confirmed-decisions) records for Sales-Region-as-tax-rule: the
> prototype stays the visual reference, never the data model. Everything else in this section —
> carriers, rate rules, validation, and the no-carrier-API boundary — is unchanged.

**The seeded geography catalog.** Zones are assembled from a catalog seeded at three levels of
granularity:

- **All ISO countries** — the same country set the storefront would ever ship to.
- **Spain's 17 autonomous communities** (comunidades autónomas).
- **All ~8,100 Spanish municipalities** (municipios, INE granularity) — chosen deliberately over
  the coarser alternatives (the 52 provinces, or provincial capitals only), because carrier rates
  in Spain are commonly quoted at municipal level.

The catalog ships as a **CSV/JSON fixture bundled in this repository** (under `database/data/`),
sourced from INE data and chunk-seeded; no third-party package supplies it.

**A shipping zone is a named, admin-created group.** A zone bundles **one or more geography-catalog
entries at any level** — it can be as narrow as a handful of municipios ("Zona Norte") or as broad
as an entire country. Admins create, rename, and delete zones freely; the geography catalog beneath
them is seeded and fixed.

**The zone's geography picker is a searchable, server-side-filtered multi-select.** With ~8,100
municipios in the catalog, a plain `<select>` — and equally a client-side filter like the media
gallery's — does not scale: the picker queries the server as the administrator types and returns a
bounded, level-grouped result set. This is a **shared component**, the same one the product
editor's Sales Region picker uses (see [2.2 Products](#22-products)).

**This catalog stays genuinely independent from the Sales Region (fiscal) catalog**, reaffirming
[assumption 4](foundations.md#assumptions--confirmed-decisions): **no merge and no shared table**, even though
both may ultimately read their country rows from the same bundled ISO-country source file. The two
model different things — a Sales Region carries a tax rate, a default flag, and Spain's *fiscal*
territories (Península, Baleares, Canarias, Ceuta, Melilla), which are neither ISO entities nor
autonomous communities; the shipping geography catalog carries autonomous communities and
municipios, which have no fiscal meaning. Editing one never affects the other.

![Shipping configuration](../images/11-envios.png)
*Shipping screen: carrier cards (SEUR, Correos, MRW, DHL) each with an enable/disable toggle and
an Activo/Inactivo state, above a rate table grouped by carrier — each rate shows a name, a zone
badge (Península / Baleares / Canarias / Unión Europea…), a weight range (kg), a price, and a
delivery estimate.*

![New shipping rate modal](../images/12-envios-modal.png)
*New shipping rate modal: rate name, carrier select, geographic zone select, min/max weight
(kg), price (€), and a delivery-time estimate.*

```gherkin
Feature: Shipping zones (extends the prototype)

  Scenario: Create a shipping zone
    Given a shipping administrator
    When they create a shipping zone named "Zona Norte"
    Then "Zona Norte" appears in the shipping zone list

  Scenario: Rename a shipping zone
    Given a shipping administrator, with a shipping zone "Zona Norte"
    When they rename it to "Cornisa Cantábrica"
    Then the zone is shown with its new name wherever it is used

  Scenario: Delete a shipping zone no rate rule references
    Given a shipping administrator, with a shipping zone "Zona Norte" referenced by no rate rule
    When they delete "Zona Norte"
    Then it no longer appears in the shipping zone list
    And it is no longer offered in the shipping rate modal's zone selector

  Scenario Outline: Assign geography entries to a zone at any level
    Given a shipping administrator editing the shipping zone "Zona Norte",
      with the geography catalog seeded
    When they add <entry> to the zone
    Then the zone covers <entry>

    Examples:
      | entry                                        |
      | the country "Francia"                        |
      | the autonomous community "Galicia"           |
      | the municipios "Gijón", "Avilés" and "Siero" |

  Scenario: The geography picker filters as the administrator searches
    Given a shipping administrator editing a shipping zone, with the geography catalog seeded
      with every country, Spain's 17 autonomous communities, and its ~8,100 municipios
    When they type "Torrelav" into the zone's geography picker
    Then only catalog entries matching that text are offered, grouped by level

  Scenario: The geography picker shows an empty state when a search matches nothing
    Given a shipping administrator editing a shipping zone
    When they search the geography picker for a term that matches no catalog entry
    Then a "no results" empty state is shown instead of a list of entries

  Scenario: The geography catalog does not allow inventing new entries
    Given a shipping administrator editing a shipping zone
    When they look for a way to add a country, autonomous community, or municipio
      that the catalog does not contain
    Then no such option exists, and only seeded catalog entries can be added to a zone

  Scenario: Creating a shipping zone leaves the Sales Region catalog untouched
    Given a shipping administrator, with the Sales Region (fiscal) catalog seeded
    When they create the shipping zone "Zona Norte"
    Then "Zona Norte" appears only in the shipping zone list
    And no Sales Region entry, rate, or default flag is changed
```

> **Pending Phase 1 confirmation — not a locked decision.** Unlike every other scenario in this
> PRD, the single scenario below has **not** been confirmed with the product owner. Making shipping
> zones deletable raised a question the fixed-list design never had: what happens to a zone a rate
> rule still points at. The scenario states the **recommendation** — a hard block with a count,
> mirroring the established convention for product categories in
> [2.2 Products](#22-products) — so the Three Amigos debate for the shipping stories has something
> concrete to accept or reject. Treat it as a proposal until that debate resolves it; the
> alternative under consideration is blocking only until the affected rate rules are reassigned
> through a guided flow.

```gherkin
Feature: Deleting a shipping zone still in use (pending Phase 1 confirmation)

  Scenario: Deleting a shipping zone still referenced by a rate rule is hard-blocked with a count
    Given a shipping administrator, with the zone "Península" referenced by 7 SEUR rate rules
    When they try to delete "Península"
    Then deletion is always blocked (no confirm-and-proceed path)
    And the message states how many rate rules reference it
      (e.g. "This zone is used by 7 shipping rates and cannot be deleted")
    And they must reassign those rate rules' zone before it can be deleted
```

```gherkin
Feature: Shipping carriers and rates

  Scenario: Enable a carrier
    Given a shipping administrator, with the "MRW" carrier disabled
    When they enable the "MRW" carrier
    Then "MRW" is marked active and its rates become usable

  Scenario: Disable a carrier
    Given a shipping administrator, with the "MRW" carrier enabled
    When they disable the "MRW" carrier
    Then "MRW" is marked inactive

  Scenario: Create a rate rule for a carrier
    Given a shipping administrator, with the carrier "SEUR" active
    When they add a "Península" rate for SEUR covering 0–2 kg at 4,95 € with "24–48h" delivery
    Then the rate appears under SEUR in the grouped rate table

  Scenario Outline: An invalid shipping rate is rejected
    Given a shipping administrator creating a shipping rate
    When they submit it with <invalid_field>
    Then the rate is rejected with a validation message

    Examples:
      | invalid_field                             |
      | a minimum weight greater than the maximum |
      | a negative price                          |
```

**Acceptance criteria — Shipping**

- [ ] Carriers can be enabled/disabled with a toggle and show an active/inactive state.
- [ ] Rate rules are created/edited/deleted per carrier with zone, weight range, price (€), and
      delivery estimate, shown grouped by carrier as in the prototype.
- [ ] Weight range (min ≤ max) and non-negative price are validated.
- [ ] Shipping zones are a **full admin-CRUD catalog** — administrators create, rename, and delete
      zones freely; zones are not a fixed seeded list.
- [ ] A **geography catalog is seeded** at three levels — all ISO countries, Spain's 17 autonomous
      communities, and all ~8,100 Spanish municipios (INE granularity) — from a CSV/JSON fixture
      bundled in this repository (`database/data/`). Administrators cannot add entries to it.
- [ ] A shipping zone is a **named group bundling one or more geography-catalog entries at any
      level**; a zone may be as narrow as a few municipios or as broad as an entire country.
- [ ] The zone's geography picker is a **searchable, server-side-filtered multi-select** with a
      "no results" empty state — the same shared component the product editor's Sales Region picker
      uses. A client-side filter is explicitly insufficient at this dataset's size.
- [ ] Shipping zones and their geography catalog are kept **independent from the Sales Region
      (fiscal) catalog** per [assumption 4](foundations.md#assumptions--confirmed-decisions): no merge and no
      shared table, even where both read country rows from the same bundled ISO-country file.
- [ ] _(Pending Phase 1 confirmation)_ Deleting a zone still referenced by a rate rule is hard-
      blocked, with a message stating how many rate rules reference it, requiring reassignment
      first — mirroring the product-category convention in [2.2 Products](#22-products).
- [ ] No external carrier API is called; all configuration is manual.

### 2.5 Payment Methods (Store Settings)

A store-settings screen where the admin configures which payment methods are available. **In
this phase, exactly one exists: bank transfer.** The bank transfer method has one configurable
field — an **IBAN** — where the admin enters the account customers must transfer payment to.
This ties to [Epic 3's Orders](epic-3-customers-orders.md#32-orders): an order's payment method references one of these
configured methods, which this phase is always bank transfer.

**No design prototype exists for this screen** — it should follow the existing card/list + edit
patterns already used for the Shipping carrier cards ([2.4 Shipping](#24-shipping)) or the Tax
rules list ([2.1 Sales Regions & Taxes](#21-sales-regions--taxes)).

```gherkin
Feature: Payment methods (store settings)

  Scenario: Bank transfer is the only payment method this phase
    Given a store administrator on the payment methods settings
    When they view the available payment methods
    Then bank transfer is the only method offered

  Scenario: Configure the bank transfer IBAN
    Given a store administrator on the payment methods settings
    When they set the bank transfer IBAN to a valid account IBAN
    Then the bank transfer method is saved with that IBAN

  Scenario: An invalid IBAN is rejected
    Given a store administrator on the payment methods settings
    When they enter an IBAN that fails IBAN-format validation
    Then the change is rejected with a validation message
```

**Acceptance criteria — Payment Methods**

- [ ] A store-settings screen lists the available payment methods; this phase, bank transfer is
      the only one.
- [ ] The bank transfer method exposes a single configurable IBAN field.
- [ ] The IBAN is validated for correct format; invalid values are rejected.
- [ ] An order's payment method references one of these configured methods (bank transfer this
      phase) — see [Epic 3 Orders](epic-3-customers-orders.md#32-orders).

---
