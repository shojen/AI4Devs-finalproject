# Arospe PRD — Epic 4 — Blog

> Part of [Arospe PRD](../PRD.md). **Read this part when:** the task is a Blog categories, tags or posts story. The other parts are listed in the [hub](../PRD.md#table-of-contents).

## Epic 4 — Blog

**Priority: 4.** The prototype's list + editor pattern stays, reusing the same WYSIWYG editor
and shared media gallery as Products. **Extensions:** a managed **category taxonomy** (full
CRUD, distinct from product categories) and **tags** (full CRUD management screen **plus**
create-on-the-fly from the post editor). A post has **one category** and **multiple tags**.

> **Technical note — UUID (v7) primary keys.** **Blog Posts, Blog Categories, and Blog Tags**
> each use a UUID (v7) primary key via Laravel's `HasUuids` trait (see
> [assumption 19](foundations.md#assumptions--confirmed-decisions)). All three are greenfield tables, created
> with the UUID PK from day one. Not yet implemented; this lands during Epic 4's TDD work.

![Blog list](../images/07-blog-lista.png)
*Blog list: article title, category, status (Borrador / Publicado / Programado), and date, with
a primary "Nuevo artículo" action.*

![Blog post editor](../images/08-blog-editor.png)
*Blog post editor: title, category select, status select, and the WYSIWYG body with image
insertion via the shared media gallery. **Extends the prototype**: category is a managed
taxonomy and a tag chip/autocomplete field is added (see scenarios).*

```gherkin
Feature: Blog posts

  Scenario: Create a post
    Given a blog editor
    When they create a post with a title, one category, a status, and a WYSIWYG body
    Then the post appears in the blog list with its status badge and date

  Scenario: Insert an image into a post body from the shared gallery
    Given a blog editor editing a post body
    When they insert an image from the shared media gallery
    Then the image is placed inline in the post body

  Scenario: A post has exactly one category
    Given a blog editor editing a post
    When they select a category
    Then the post has exactly that one category
```

```gherkin
Feature: Blog categories (extends the prototype)

  Scenario: Create a blog category
    Given a blog editor
    When they create a blog category named "Guías"
    Then it appears in the post editor's category selector

  Scenario: Rename a blog category
    Given a blog editor, with a blog category "Guías"
    When they rename it to "Guías de compra"
    Then the category is shown with its new name wherever it is used

  Scenario: Delete an unused blog category
    Given a blog editor, with a blog category "Guías" assigned to no posts
    When they delete "Guías"
    Then it no longer appears in the post editor's category selector

  Scenario: Blog categories are independent from product categories
    Given a blog editor
    When they view the blog category list
    Then it contains only blog categories, separate from product categories

  Scenario: Deleting a blog category still in use is hard-blocked with a count
    Given a blog editor, with a blog category assigned to 5 posts
    When they try to delete that category
    Then deletion is always blocked (no confirm-and-proceed path)
    And the message states how many posts use it
      (e.g. "This category is used by 5 posts — reassign them before deleting")
    And they must reassign those posts before it can be deleted
```

```gherkin
Feature: Blog tags (extends the prototype)

  Scenario: Create a tag on the management screen
    Given a blog editor on the tag management screen
    When they create a tag named "running"
    Then the tag "running" becomes available to posts

  Scenario: Rename a tag on the management screen
    Given a blog editor on the tag management screen, with a tag "running"
    When they rename it to "trail running"
    Then the tag is shown as "trail running" everywhere it is used

  Scenario: Delete a tag on the management screen
    Given a blog editor on the tag management screen, with a tag "running"
    When they delete the "running" tag
    Then it is removed from every post that used it

  Scenario: Reuse an existing tag from the post editor
    Given a blog editor editing a post, with a tag "running" already existing
    When they add "running" from the tag field
    Then the existing "running" tag is attached, not duplicated

  Scenario: Create a new tag on the fly from the post editor
    Given a blog editor editing a post, with no tag named "invierno"
    When they type "invierno" in the tag field and confirm it
    Then a new "invierno" tag is created and attached to the post

  Scenario: A post can hold more than one tag
    Given a blog editor editing a post that already has the tag "running"
    When they add the tag "invierno"
    Then the post is associated with both "running" and "invierno"

  Scenario Outline: Filter the blog list by taxonomy
    Given a blog editor viewing the blog list across several categories and tags
    When they filter the list by <filter>
    Then only posts matching <filter> are shown

    Examples:
      | filter     |
      | a category |
      | a tag      |
```

**Acceptance criteria — Blog**

- [ ] Posts have a title, exactly one category, multiple tags, a status, a date, and a WYSIWYG
      body with shared-gallery image insertion, matching the prototype list+editor.
- [ ] Blog categories have full CRUD and are distinct from product categories; a category in use
      **cannot** be deleted (hard block, no confirm-and-proceed) and the message states how many
      posts use it, requiring reassignment first.
- [ ] Tags have a full CRUD management screen **and** can be created on the fly from the post
      editor; typing an existing name reuses it, a new name creates it immediately.
- [ ] The admin blog list can be filtered by category and by tag.
- [ ] The data relationships support a future storefront filtering by category/tag (the
      **public-facing** filtering UI is out of scope — see [Out of scope](roadmap-scope-open-questions.md#out-of-scope)).
- [ ] Blog Posts, Blog Categories, and Blog Tags each use a UUID (v7) primary key, via Laravel's
      `HasUuids` trait, applied at both the migration and Eloquent model level.

---
