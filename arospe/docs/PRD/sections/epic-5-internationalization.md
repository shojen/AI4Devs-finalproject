# Arospe PRD — Epic 5 — Internationalization

> Part of [Arospe PRD](../PRD.md). **Read this part when:** the task is a locale, language switcher or translatable-content story. The other parts are listed in the [hub](../PRD.md#table-of-contents).

## Epic 5 — Internationalization

**Priority: 5 (build last).** It cross-cuts Products and Blog (and, indirectly, the taxonomies
their content shares), so it comes after those exist. Two **independent** layers — do not
conflate them.

**Layer 1 — Admin UI language switcher.** A selector in the dashboard chrome switches the
interface language between **Spanish and English only** (menus, labels, buttons), via standard
Laravel localization (`lang/` files — greenfield; no `lang/` directory exists yet). This does
**not** translate store content.

**Layer 2 — Store Languages.** A separate settings section where admins manage which languages
the store's **content** is authored in — add/remove a language (e.g. add French), and mark one as
the store default. Each active store language then appears as a **tab inside the Product and Blog
editors** (and in the taxonomy management screens), switching the translatable fields in place.

**Translatable content (per store language):**

- Product **title** and **description**.
- Blog post **title** and **body**.
- **Slug / SEO fields** (e.g. URL slug, meta title/description) on products and posts.
- **Category and tag names** — Product categories, Blog categories, and Blog tags — each becomes
  a per-store-language field with the same tab-based editor UX.

**Non-translatable** fields (price, stock, SKU, status, dates) stay **outside** the language
tabs and are shown once.

**Store default language.** The store default is **independent** of the admin UI language (the
UI stays ES/EN only) and can be **any active store language** the admin has added (e.g. French).
On **initial installation**, the store's out-of-the-box default language is **Spanish**; admins
can later change the default to any other active store language.

```gherkin
Feature: Admin UI language switcher (Layer 1)

  Scenario: Switch the interface language to English
    Given a signed-in administrator using the interface in Spanish
    When they choose English from the admin language switcher
    Then the menus, labels, and buttons are shown in English
    And the choice persists across their sessions

  Scenario: The interface switcher offers only Spanish and English
    Given a signed-in administrator
    When they open the admin language switcher
    Then only Spanish and English are offered
    And store content languages do not appear in this switcher
```

```gherkin
Feature: Store Languages (Layer 2)

  Scenario: Spanish is the default store language on a fresh install
    Given a store administrator on a fresh installation
    When they open the Store Languages settings for the first time
    Then Spanish is the store's default language

  Scenario: Add a store language
    Given a store administrator, with store languages Spanish and English
    When they add French as a store language
    Then a French tab appears in the Product and Blog editors and taxonomy screens

  Scenario: The store default language is independent of the admin UI language
    Given a store administrator, with French active as a store language
    When they set French as the store's default language
    Then French becomes the store default
    And the admin UI language options remain only Spanish and English

  Scenario: Switching an editor's language tab switches only translatable fields
    Given a catalog administrator in a product editor showing Spanish, English, and French tabs
    When they switch from the Spanish tab to the French tab
    Then the title, description, and slug/SEO fields show the French content
    And the price, stock, SKU, status, and dates stay unchanged and are shown once

  Scenario Outline: Taxonomy names are translatable per store language
    Given a store administrator, with French active as a store language
    When they edit the name of a <taxonomy>
    Then they can provide its name per active store language via language tabs

    Examples:
      | taxonomy         |
      | Product category |
      | Blog category    |
      | Blog tag         |

  Scenario: Removing a store language warns before affecting translations
    Given a store administrator, with French active and holding existing translations
    When they remove French as a store language
    Then they are warned before any French translation content is affected
    And the French tab no longer appears in the editors

  Scenario: A missing translation falls back to the default store language
    Given a store administrator, with a product that has Spanish (default) content
      but no French translation
    When the product's French version is requested
    Then the Spanish default content is used as the fallback
```

**Acceptance criteria — Internationalization**

- [ ] The admin UI switcher toggles the interface between Spanish and English only, persists the
      choice, and uses standard Laravel `lang/` localization.
- [ ] Store Languages are admin-managed (add/remove, one default) and independent from the UI
      switcher; on install the store default is Spanish, and it can later be changed to any
      active store language.
- [ ] Each active store language surfaces as a tab in the Product and Blog editors and in the
      taxonomy management screens, switching only the translatable fields.
- [ ] Translatable fields are: product title/description, post title/body, slug/SEO fields on
      products and posts, and Product-category / Blog-category / Blog-tag names.
- [ ] Non-translatable fields (price, stock, SKU, status, dates) appear once, outside the tabs.
- [ ] Removing a store language warns the admin and there is a defined fallback to the default
      store language for missing translations.

---
