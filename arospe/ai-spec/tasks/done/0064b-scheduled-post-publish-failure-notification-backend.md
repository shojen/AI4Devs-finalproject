# [0064b] Scheduled post publish failure — notification and email to the post's creator (backend)

## Description

When the every-minute sweep of story [0064](done/0064-scheduled-post-auto-publish-backend.md) cannot take a
scheduled post live, nobody is told today: the command `report()`s the exception, counts it as `failed`, prints
`N failed; see the log.` and carries on with exit code 0. A post that was promised to the public at a given
minute can therefore sit `Scheduled` for days, or go live without the follow-up announcement 0065 adds, with the
only evidence in a log file no editor reads.

This story tells **the person who can fix it**. When a scheduled post fails, the post's **creator** receives a
**database notification** (the dashboard bell) **and an email** saying **what failed**, **which post**, and giving
**a link to edit that post and a link to the posts page in the dashboard**. If the creator cannot be reached, the
administrators who hold `blog.edit` receive the same message instead.

**It handles two distinct failures through one capture site** (the sweep command's existing `catch`):

| Stage | What failed | State of the post afterwards | Retried? |
| --- | --- | --- | --- |
| `publish` | the sweep's write (the conditional `UPDATE`) threw | still `Scheduled` | **yes, every minute**, so the message must not repeat every minute (**D-6**) |
| `announce` | the write succeeded (post is `Published`) but a synchronous listener of `ScheduledBlogPostPublished` threw from `dispatch()` (0065's, once it ships) | `Published` | **no**, at-most-once (0064's Phase 5 finding) |

It adds **one nullable column** (`blog_posts.created_by`, the first author attribution on a blog post), **one enum**,
**one notification class**, **one ungated system action** and **one guarded call** in the existing command. It adds
**no route, no Livewire component, no view, no permission and no policy**.

**Origin.** Raised by the human owner while closing story 0064 (decision 4A, extended), verbatim: *"if it fails,
create a notification, and also send an email to the user who created the post, saying what failed, which post, and a
link to edit it or to the posts page in the dashboard."* Found while closing 0064, so it is numbered `0064b`; the
ordering rule is satisfied because every dependency (0064, 0063) has a lower number
([task ordering rule](../../docs/workflow/task-files-links-and-ordering.md#task-ordering-rule)).

> ### BLOCKED — read this before Phase 3
>
> Fully specified now, but Phase 3 cannot start until **both** of the following are true:
>
> - [0064](done/0064-scheduled-post-auto-publish-backend.md) is merged (owns the command, the action and the
>   event this story hooks into; it is the branch this file was written on).
> - [0063](done/0063-blog-posts-list-editor-ui.md) is `done` — it **owns the two routes the email links to**
>   (`blog-posts.edit` and `blog-posts.index`, its **D-3**). Neither route exists today (`routes/` has no
>   `blog-posts.php`), and `route()` throws `RouteNotFoundException` for an unknown name. The owner's wording
>   ("a link to edit it or to the posts page") needs those routes. The alternative — link to `dashboard` now and
>   switch later — is **OQ-1**; blocking is recommended.
>
> **Do not stub `routes/blog-posts.php`, a `blog-posts.edit` route or a placeholder Livewire component to make this
> testable earlier.** That is the back door 0065's banner refuses for the same reason.
>
> **This story also amends two other files' contracts** (0064's hand-off item 7 and 0065's listener); see **D-0** and
> the [hand-offs](#hand-offs-other-stories-and-files-this-story-changes-the-contract-of).

## Type

backend | includes database-expert: **yes**

`database-expert` is convened because this story adds a column and a foreign key to a table that already carries a
purpose-built composite index and a pending retrofit (0078). The questions it answered: the delete behaviour
(`nullOnDelete`, and why it will essentially never fire, **D-7**); whether the FK needs its own index (no, InnoDB
creates one, and **the table now has five indexes, not four**); whether to backfill (no); the column's position
against 0078's migration; and why **no schema at all** is the right home for the dedup state (**D-6**).

## Three Amigos participants

`product-owner` (lead/facilitator) + `backend-expert` (classes, the single capture site, the queue and mail shape) +
`backend-qa` (risk-based test design and **three corrections that changed this document**: resolve recipients before
claiming the dedup key, gate the creator on `blog.edit` not `blog.view`, and escape the title in the email) +
`database-expert` (the FK, the index list, the no-backfill and no-dedup-column decisions). All contributions are
reflected below, with the facilitator's own findings marked. See [Provenance](#provenance).

## Gherkin

Every scenario carries exactly one `When` (rule 3) and opens with a named business-role actor, never `I` (rule 1),
per [gherkin-guidelines.md](../../docs/testing/frontend/gherkin-guidelines.md). Where the acting subject is the
scheduler, the scenarios follow
[Scenarios whose actor is not a person](../../docs/testing/frontend/gherkin-guidelines.md#scenarios-whose-actor-is-not-a-person-scheduled-and-system-triggered):
the `When` names **the publication scheduler**, and the `Given` carries the **blog editor** whose earlier decision
created the state. The failure itself is stated as a fact of the world (*"publishing that post fails"*), never as a
technical mechanism (rule 2).

```gherkin
Feature: A scheduled post that fails to go live is reported to the person who can fix it

  # --- Stage "publish": the post could not be published ---

  Scenario: The creator is told in the dashboard that a scheduled post could not be published
    Given a blog editor who created a post scheduled for a time that has now arrived
    And publishing that post fails
    When the publication scheduler runs
    Then the blog editor has a dashboard notification saying that post could not be published

  Scenario: The creator is also emailed, with a link to edit the post and to the posts page
    Given a blog editor who created a post titled "Guía de invierno" scheduled for a time that has now arrived
    And publishing that post fails
    When the publication scheduler runs
    Then the blog editor receives an email naming "Guía de invierno" and saying it could not be published automatically
    And the email links to that post's editor
    And the email links to the list of all blog posts

  Scenario: A post that could not be published stays scheduled
    Given a blog editor who created a post scheduled for a time that has now arrived
    And publishing that post fails
    When the publication scheduler runs
    Then the post is still scheduled

  Scenario: The next run publishes the post once the fault is gone and says nothing more
    Given a blog editor who was told a scheduled post could not be published
    And the fault that blocked that post has since been fixed
    When the publication scheduler runs
    Then the post is published
    And the blog editor is not notified again

  Scenario: One failing post does not stop the others going live
    Given a blog editor who created three posts scheduled for times that have now arrived
    And publishing one of them fails
    When the publication scheduler runs
    Then the other two posts are published
    And the blog editor is told about the one that failed

  # --- A repeated failure must not flood the creator (the sweep retries every minute) ---

  Scenario: A post that keeps failing is not announced again straight away
    Given a blog editor who was told this morning that a scheduled post could not be published
    And publishing that post still fails
    When the publication scheduler runs again
    Then the blog editor is not notified a second time

  Scenario: A post still failing a day later is reported again
    Given a blog editor who was told yesterday that a scheduled post could not be published
    And publishing that post still fails
    When the publication scheduler runs a day later
    Then the blog editor is notified again

  Scenario: Rescheduling a failed post starts a fresh report
    Given a blog editor who was told a scheduled post could not be published
    And the blog editor rescheduled that post for a later time that has now arrived
    And publishing that post fails again
    When the publication scheduler runs
    Then the blog editor is notified again

  Scenario: Two different failing posts are each reported
    Given a blog editor who created two posts scheduled for times that have now arrived
    And publishing both fails
    When the publication scheduler runs
    Then the blog editor has two separate notifications, one per post

  # --- Stage "announce": the post went live but the follow-up failed ---

  Scenario: The creator is told the post went live but the announcement to administrators failed
    Given a blog editor who created a post scheduled for a time that has now arrived
    And announcing a published post to administrators fails
    When the publication scheduler runs
    Then the post is published
    And the blog editor is told the post was published but the follow-up notification could not be sent

  Scenario: A post whose announcement failed is not published again on the next run
    Given a blog editor who was told a post was published but its announcement failed
    When the publication scheduler runs again
    Then the post remains published
    And the blog editor is not notified again

  # --- Who is told ---

  Scenario Outline: Administrators are told instead when the creator cannot be reached
    Given a post created by a blog editor who <situation>
    And a blog administrator whose role grants the blog edit permission
    And that post is scheduled for a time that has now arrived and publishing it fails
    When the publication scheduler runs
    Then the blog administrator is told the post could not be published
    And the blog editor who created it is not

    Examples:
      | situation                                  |
      | has been deleted                           |
      | has been suspended                         |
      | is inactive                                |
      | no longer holds the blog edit permission   |

  Scenario: A post created before creators were recorded is reported to the administrators who can edit it
    Given a post with no recorded creator, scheduled for a time that has now arrived
    And a blog administrator whose role grants the blog edit permission
    And publishing that post fails
    When the publication scheduler runs
    Then the blog administrator is told the post could not be published

  Scenario: An administrator who can only view the blog is not told
    Given a post created by a blog editor who has been deleted
    And an administrator whose role grants the blog view permission but not the blog edit permission
    And publishing that post fails
    When the publication scheduler runs
    Then that administrator is not notified

  Scenario: A Super Admin who created the post is told
    Given a Super Admin who created a post scheduled for a time that has now arrived
    And publishing that post fails
    When the publication scheduler runs
    Then the Super Admin is told the post could not be published

  Scenario: A Super Admin is not among the administrators told in the creator's place
    Given a post whose creator has been deleted
    And a Super Admin, who holds no explicit blog edit grant
    And publishing that post fails
    When the publication scheduler runs
    Then the Super Admin is not notified

  Scenario: Nobody who can fix the post is reachable
    Given a post whose creator has been deleted, and no administrator holds the blog edit permission
    And publishing that post fails
    When the publication scheduler runs
    Then nobody is notified
    And the run completes without error

  # --- What the message contains, and what it never contains ---

  Scenario: The message never contains technical error detail
    Given a blog editor who created a post scheduled for a time that has now arrived
    And publishing that post fails with an internal error message
    When the publication scheduler runs
    Then neither the dashboard notification nor the email contains that internal error message

  Scenario: A title containing markup is shown as plain text
    Given a blog editor who created a post whose title contains a web link and formatting characters
    And that post is scheduled for a time that has now arrived and publishing it fails
    When the publication scheduler runs
    Then the email shows the title as plain text
    And the email contains no link other than the edit link and the posts-page link

  # --- The scheduler stays quiet when nothing is wrong, and never breaks because of the notice ---

  Scenario: Nobody is notified when publishing works
    Given a blog editor who created a post scheduled for a time that has now arrived
    When the publication scheduler runs
    Then the post is published
    And nobody is notified of a failure

  Scenario: A dry run notifies nobody
    Given a blog editor who created a post scheduled for a time that has now arrived
    When an operator lists the due posts with a dry run
    Then nobody is notified

  Scenario: A problem sending the notice does not stop the run
    Given a blog editor who created two posts scheduled for times that have now arrived
    And publishing the first fails and the failure notice itself cannot be sent
    When the publication scheduler runs
    Then the second post is published
    And the run completes without error

  # --- The creator is recorded (the data the notice depends on) ---

  Scenario: A new post records who created it
    Given a blog editor
    When they create a post
    Then the post records that blog editor as its creator

  Scenario: Editing a post does not change its creator
    Given a post created by a blog editor
    When another blog editor edits that post
    Then the post still records the first blog editor as its creator
```

> **Two scenarios are business-language stand-ins for behaviour the tests pin at a lower level.** *"Publishing that
> post fails"* and *"announcing … fails"* are the two failure classes; the test plan drives them with
> `FailingBlogPostWrites::next()` and a test-registered throwing listener respectively. The Gherkin never names either
> mechanism.

## Files to create/modify

### Schema and model — the creator

| Path | What & why |
| --- | --- |
| `database/migrations/<timestamp>_add_created_by_to_blog_posts_table.php` | **New.** Alteration naming per [basics-and-alterations.md](../../docs/database/migrations/basics-and-alterations.md) (`<verb>_<what>_to_<table>_table`). The `<timestamp>` must sort **after** `2026_09_24_162609` (the latest migration today) and **before** 0078's own migration. |
| `app/Models/BlogPost.php` | **Modify.** PHPDoc `@property string\|null $created_by` and `@property-read User\|null $creator`; a `creator(): BelongsTo` relation. **`created_by` is deliberately not added to `#[Fillable]`.** |
| `app/Actions/Blog/CreateBlogPost.php` | **Modify — one line.** The `forceCreate()` literal gains `'created_by' => Auth::id()`, from the authenticated actor, after `authorize()` has passed. |
| `database/factories/BlogPostFactory.php` | **Modify.** `definition()` gains `'created_by' => null`; a new `createdBy(User $user): static` state. |

```php
// database/migrations/<timestamp>_add_created_by_to_blog_posts_table.php
public function up(): void
{
    Schema::table('blog_posts', function (Blueprint $table): void {
        // NOT `constrained()` with no argument: Laravel would infer a `created_bies` table.
        // nullOnDelete, not restrict (D-7). No explicit index: InnoDB creates the FK's own.
        $table->foreignUuid('created_by')->nullable()->after('published_at')
            ->constrained('users')->nullOnDelete();
    });
}

public function down(): void
{
    Schema::table('blog_posts', function (Blueprint $table): void {
        $table->dropForeign(['created_by']);
        $table->dropColumn('created_by');
    });
}
```

The migration's docblock states, in the file: **no backfill** (every pre-existing row stays `NULL`; who created a legacy
post is unknowable and a guess would be invented data) and that `NULL` is a first-class state the notifier handles
(**D-8**).

### The failure notice

| Path | What & why |
| --- | --- |
| `app/Enums/BlogPostPublishFailureStage.php` | **New.** String-backed enum, two cases: `Publish = 'publish'`, `Announce = 'announce'`. `app/Enums/` is a stock location. Used in the notification payload as `->value`, in the dedup key and in the mail copy selection. |
| `app/Notifications/ScheduledBlogPostPublishFailed.php` | **New.** The notification (**D-4**, **D-5**, **D-9**). |
| `app/Actions/Blog/NotifyScheduledBlogPostPublishFailed.php` | **New.** The ungated system action: re-read, classify, resolve recipients, claim the dedup key, send (**D-2**, **D-3**, **D-6**, **D-8**). In `app/Actions/Blog/`, the domain-area folder, never `app/Actions/Notifications/` (0065's and 0056's **D-2** reasoning). |
| `app/Console/Commands/PublishScheduledBlogPosts.php` | **Modify — the `catch` only** (**D-11**). |
| `lang/en/notifications.php`, `lang/es/notifications.php` | **Modify.** A new `mail.blog_post_publish_failed.*` group, key-for-key identical (**D-9**). |

```php
// app/Notifications/ScheduledBlogPostPublishFailed.php -- the shape, not the wording
class ScheduledBlogPostPublishFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $blogPostId,
        public readonly ?string $title,           // frozen snapshot, like 0065's D-4a
        public readonly BlogPostPublishFailureStage $stage,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /** @return array{blog_post_id: string, title: ?string, stage: string} */
    public function toArray(object $notifiable): array
    {
        return ['blog_post_id' => $this->blogPostId, 'title' => $this->title, 'stage' => $this->stage->value];
    }

    public function toMail(object $notifiable): MailMessage { /* D-9, D-10 */ }
}
```

```php
// app/Actions/Blog/NotifyScheduledBlogPostPublishFailed.php -- the ORDER is the contract (D-3)
public function __invoke(string $blogPostId): void
{
    try {
        $post = BlogPost::query()->find($blogPostId);            // scoped: missing or soft-deleted => null
        $stage = $post === null ? null : $this->stageFor($post); // one re-read supplies status AND title
        if ($stage === null) {
            return;                                              // nothing (still) to report
        }

        $recipients = $this->recipients($post);                  // BEFORE the key is claimed
        if ($recipients->isEmpty()) {
            Log::warning('Scheduled blog post failure has no reachable recipient',
                ['blog_post_id' => $post->id, 'stage' => $stage->value]);
            return;
        }

        $key = sprintf('blog-publish-failed:%s:%s:%d', $post->id, $stage->value, $post->published_at?->timestamp ?? 0);
        if (! Cache::add($key, 1, now()->addDay())) {            // atomic on the database store (V-5)
            return;                                              // already reported in this episode
        }

        try {
            Notification::send($recipients, new ScheduledBlogPostPublishFailed($post->id, $post->title, $stage));
        } catch (Throwable $e) {
            Cache::forget($key);                                 // a failed send must not silence the post for 24h
            throw $e;
        }
    } catch (Throwable $e) {
        report($e);                                              // never rethrown: the sweep must go on
    }
}
```

### Consumed, not created by this story

`App\Actions\Blog\PublishScheduledBlogPost` and `App\Events\Blog\ScheduledBlogPostPublished` — **not changed** (**D-1**).
`App\Models\User` (`Notifiable`, `SoftDeletes`, `HasRoles`), `App\Enums\UserStatus`, the `notifications` table (story
[0043](done/0043-customers-new-customer-notification-backend.md)), `blog.edit` (seeded, `RolePermissionSeeder::MODULES`
contains `blog`), the `cache` and `jobs` tables (`0001_01_01_000001_create_cache_table.php`,
`0001_01_01_000002_create_jobs_table.php`).

### Explicitly **not** touched

`app/Actions/Blog/PublishScheduledBlogPost.php` and `app/Events/Blog/ScheduledBlogPostPublished.php` (**D-1**) ·
`app/Actions/Blog/UpdateBlogPost.php` (the creator is immutable, **D-7**) · `routes/**` (the two routes are 0063's) ·
`app/Livewire/**`, `resources/views/**` and the bell (a follow-up, **H-3**) · `config/**` · `database/seeders/**` ·
`app/Policies/**` and any permission · `bootstrap/app.php` and `routes/console.php` (the schedule entry is unchanged).

### Test files

| Path | What & why |
| --- | --- |
| `tests/Feature/Blog/BlogPostCreatorTest.php` | **New.** The column, the FK, the actor stamp, the relation. |
| `tests/Feature/Blog/NotifyScheduledBlogPostPublishFailedTest.php` | **New.** Recipient resolution, dedup, classification, ordering, containment. |
| `tests/Feature/Blog/ScheduledBlogPostPublishFailedNotificationTest.php` | **New.** Payload privacy, mail content, copy parity, channels, one un-faked end-to-end. |
| `tests/Feature/Console/Commands/PublishScheduledBlogPostsTest.php` | **Extend.** The catch integration. |
| `tests/Support/Blog/ScheduledPosts.php` | **Extend.** A `createdBy` option so the creator is set at the fixture, not patched afterwards. |

## Tests to perform

Each item states the **risk if missing** and the **mutation** that must turn it red. The Definition of Done requires
each mutation to be run once and recorded, as 0064's did.

Conventions inherited from
[scheduled-commands.md](../../docs/testing/backend/scheduled-commands.md): freeze the clock (`Carbon::setTestNow()`,
reset in `afterEach`); **no `actingAs()`** for the notifier and the command paths (the system action reads no actor, so
assert `Auth::check()` is `false`); force the write to fail with `FailingBlogPostWrites::next()`; assert a swallowed
exception reached the log with `Log::spy()`. `phpunit.xml` pins `CACHE_STORE=array`, `MAIL_MAILER=array` and
`QUEUE_CONNECTION=sync` (**V-8**), so a queued notification runs inline in the un-faked test.

### `tests/Feature/Blog/BlogPostCreatorTest.php`

- [ ] **Migration shape.** `created_by` is a nullable `CHAR(36)` FK to `users.id` with `ON DELETE SET NULL`, via
      `Schema::getColumns()` / `Schema::getForeignKeys()`; **and `blog_posts` has exactly five indexes** (`primary`,
      `blog_posts_slug_unique`, `blog_posts_blog_category_id_foreign`, the `(deleted_at, status, published_at)`
      composite, `blog_posts_created_by_foreign`) — no hand-written index on `created_by`.
      *Risk:* a `constrained()` that infers the wrong table, or a `restrictOnDelete()` that blocks deleting a user.
      *Mutation:* `restrictOnDelete()`; `->index('created_by')`.
- [ ] **`nullOnDelete` actually nulls.** Hard-delete the creator with a raw
      `DB::table('users')->where('id', …)->delete()` (the raw statement, exactly as `ProductMediaTest` drives
      `media.uploaded_by`, because `User::delete()` is a soft delete that never fires the FK): `created_by` becomes
      `NULL` and the post survives.
      *Risk:* the clause is documentation with no proof. *Mutation:* `restrictOnDelete()` (throws 23000).
- [ ] **`CreateBlogPost` stores the authenticated actor** (the one test in this file that *does* `actingAs()`, a user
      with `blog.create`), and the stored value is the actor's id, not a parameter.
      *Mutation:* drop the `'created_by'` line; hard-code `null`.
- [ ] **`created_by` is not mass-assignable:** `BlogPost::create([... 'created_by' => $other->id])` throws
      `MassAssignmentException` or leaves it `NULL`, whichever the model's guard does — assert the behaviour of the
      sibling `slug`/`published_at` test. *Mutation:* add `created_by` to `#[Fillable]`.
- [ ] **`UpdateBlogPost` by a different editor does not change `created_by`;** a raw
      `$post->update(['title' => …])` does not either. *Risk:* the "created by" becomes "last edited by" silently.
      *Mutation:* a `forceFill(['created_by' => Auth::id()])` in `UpdateBlogPost`.
- [ ] **A soft-deleted creator resolves `creator()` to `null`** while the raw column stays populated (the
      `media.uploaded_by` caveat, pinned so nobody "fixes" it into `withTrashed()`).
- [ ] **Factory:** default `created_by` is `null`; `->createdBy($user)` sets it.
- [ ] **A legacy `NULL` row does not break the sweep:** `ScheduledPosts::scheduled()` (no creator) publishes normally
      through the command. *Risk:* a `->creator->id` dereference in the sweep path.
- [ ] **Rollback:** `down()` drops the FK and the column (run the migration's `down()`/`up()` pair in a
      `RefreshDatabase`-safe way, or assert the source shape if the suite forbids it — Phase 3 decides, records why).

### `tests/Feature/Blog/NotifyScheduledBlogPostPublishFailedTest.php`

**Classification (D-2).**

- [ ] Post `Scheduled` and due → notification with stage `publish`; the post is **still `Scheduled`**
      (drive with `FailingBlogPostWrites::next()` through the command). *Mutation:* hard-code `Announce`.
- [ ] A **test-registered throwing listener** on `ScheduledBlogPostPublished` (`Event::listen(…, fn () => throw new
      RuntimeException(MARKER))`, the shape 0064's own test uses) → stage `announce`; the post is **`Published`**.
      *Mutation:* classify from the exception type instead of the re-read.
- [ ] A post that is **`Published` when re-read → `announce`, whatever caused it.** This is the **documented limit**: a
      concurrent manual publish between a failed write and the re-read reads as `announce` (wrong message, accepted).
      Named so a future change to that trade-off is a decision.
- [ ] A post that is **`Scheduled` but no longer due** (rescheduled between the failure and the re-read), a `Draft`,
      a **missing** post and a **soft-deleted** post → **nothing sent, nothing reported**.
      *Risk:* a "could not be published at its scheduled time" mail about a post that is no longer scheduled or no
      longer exists. *Mutation:* drop the `published_at <= now()` clause; use `withTrashed()`.
- [ ] The re-read itself throws (a `beforeExecuting` hook on `select … from blog_posts`) → **reported, nothing sent,
      no rethrow** (**OQ-2**).

**Recipients (D-8).**

- [ ] **A dataset of every unreachable-creator variant falls back to the `blog.edit` holders:** `created_by` `NULL`;
      creator soft-deleted; creator `Inactive`; creator `Suspended`; creator with an empty email; creator who
      **lost `blog.edit`** (holds only `blog.view`). In each, the creator receives nothing and the seeded
      `blog.edit` administrator receives exactly one notification.
      *Risk:* mail to a person who cannot act (or, for a soft-deleted user, to an obfuscated address — `User::delete()`
      rewrites `users.email`, **V-9**). *Mutation:* drop each predicate in turn; each dataset row must have a killer.
- [ ] A reachable creator (Active, has email, holds `blog.edit`) is the **only** recipient — the administrators do not
      also get it. *Mutation:* union the two sets.
- [ ] A **Super Admin creator is reachable** through `Gate::before` even with no explicit `blog.edit` row.
      *Mutation:* replace `->can()` with `hasPermissionTo()`/a permission query.
- [ ] **The fallback excludes** the Super Admin (no explicit grant), a soft-deleted holder, a non-`Active` holder, a
      holder with an empty email, and a **view-only** administrator. It includes a holder through a **role** and a
      holder through a **direct** permission. *Mutation:* `permission('blog.view')`; drop the `status` filter.
- [ ] **Nobody reachable → nothing sent, and exactly one log line** carrying `blog_post_id` and `stage` and nothing
      else: `Log::shouldHaveReceived('warning')->once()->with(<fixed message>, ['blog_post_id' => …, 'stage' => …])`.
      Nothing is reported (it is not an error).
- [ ] **No leak of a title to a person without access:** with three users (creator gone; one `blog.edit` holder; one
      `customers.view`-only holder), only the `blog.edit` holder's `notifications` row contains the title.

**Dedup (D-6).**

- [ ] **Two failing ticks → one notification:** `FailingBlogPostWrites::next(2)`, run the command twice; one
      notification for the creator, not two. *Mutation:* remove the `Cache::add` guard.
- [ ] **The key is per stage:** a `publish` report does not suppress a later `announce` report for the same post and
      episode. *Mutation:* drop the stage from the key.
- [ ] **Rescheduling starts a new episode:** change `published_at`, fail again → a second notification.
      *Mutation:* drop `published_at` from the key.
- [ ] **A day later reminds:** advance the clock past 24 h with `Carbon::setTestNow()` (the array store honours it,
      **V-5**); assert it does before relying on it. *Mutation:* `now()->addYears(1)`.
- [ ] **Rescheduling to the very same `published_at` within 24 h stays suppressed** (documented limit).
- [ ] **A cache-write failure is skipped and reported, never fatal:** swap the cache store for one whose `add()`
      throws; nothing is sent, the exception is `report()`ed, the call does not throw.
- [ ] **The key is released when the send throws:** bind a notification dispatcher whose `send()` throws; the key is
      absent afterwards, so the next tick can retry. *Mutation:* delete the `Cache::forget`.
- [ ] **The key is not claimed when there is no recipient:** with no reachable recipient, `Cache::has($key)` is
      `false`; then adding a `blog.edit` holder and running again notifies. *Mutation:* claim before resolving
      (QA's correction; without it an empty set silences the post for 24 h).

**Ungated system action (D-3).**

- [ ] The action works with **no authenticated user** (`Auth::check()` is `false`) and reads no actor.
      *Mutation:* add a `Gate::authorize()` — the notifier would then send nothing and look like a quiet system.
- [ ] **Containment:** with the notification layer made to throw in every way the test can (dispatcher, cache,
      recipient query), `__invoke()` returns normally and the exception is `report()`ed exactly once.

### `tests/Feature/Blog/ScheduledBlogPostPublishFailedNotificationTest.php`

- [ ] **Payload privacy.** Fail the write with a distinctive marker in the exception message
      (`Simulated … MARKER-7f3a`). The marker is **absent** from: the `notifications.data` JSON, the rendered
      `MailMessage` (html **and** plain text, subject included), and every line the notifier itself logs.
      *Risk:* exception text or SQL reaching an inbox — the worst realistic leak of this story. *Mutation:* put
      `$e->getMessage()` in `toArray()`, in `toMail()`, or in the notifier's `Log::warning`.
- [ ] **`data` has exactly three keys** — `blog_post_id`, `title`, `stage` — pinned with `->toBe([...])`, not
      `toHaveKeys` (an added key is permanent in a column with no update path, **D-4**).
- [ ] **The serialised notification holds no Eloquent model and no exception** (`serialize($notification)` contains
      neither `App\Models\` nor `Throwable`): only a string, a `?string` and an enum reach the `jobs` payload.
- [ ] **Copy parity:** `lang/en/notifications.php` and `lang/es/notifications.php` carry the same keys in **both**
      directions (`BellTest` already asserts one direction for the whole file, **V-13**; this adds the reverse for the
      new group).
- [ ] **The subject contains the title**; there is **one line per stage** (`publish` mentions the automatic retry and
      the 24-hour quiet period; `announce` mentions the follow-up notification); the subject is a **single line**.
- [ ] **The buttons:** the primary button reads "Edit post" and points at `url(route('blog-posts.edit', $post))`; a
      plain "All posts" link points at `url(route('blog-posts.index'))`. **The set of `href`s in the rendered HTML is
      exactly those two URLs** (the button also repeats its own URL in the template's sub-copy). Link tests stop at
      the route name: they do not render 0063's screens.
- [ ] **A hostile title is neutralised:** `[x](https://evil.example)`, `<b>bold</b>`, `<script>`, and a title with an
      embedded newline. Rendered HTML contains no `href` for `evil.example`, no `<b>`/`<script>` element from the
      title, and the subject is one line; the visible text still shows the title's characters.
      *Risk:* a phishing link, or HTML, inside a trusted email — `Markdown::render` HTML-encodes `<`/`>` but does
      not neutralise Markdown syntax, and `withSecuredEncoding()` is off (**V-7**). *Mutation:* pass the raw title
      to `line()` and to `subject()`.
- [ ] **A `null` title uses the fallback wording** (`notifications.mail.blog_post_publish_failed.untitled`) in subject
      and body; nothing renders "null" or an empty pair of quotes.
- [ ] **Channels and queueing:** `via()` returns exactly `['database', 'mail']`; the class implements
      `ShouldQueue`; `Notification::fake()` + `assertSentTo($recipient, ScheduledBlogPostPublishFailed::class)`.
      With `Queue::fake()`, one `SendQueuedNotifications` job is pushed **per channel per recipient** (**V-6**).
- [ ] **One un-faked end-to-end test** (nothing faked): a failing sweep leaves a real `notifications` row whose
      `type` is `App\Notifications\ScheduledBlogPostPublishFailed` and a real message in the `array` mail transport
      addressed to the creator. This is the test that catches a `via()` typo or a broken `toMail()` that every faked
      test hides.

### `tests/Feature/Console/Commands/PublishScheduledBlogPostsTest.php` (extend)

- [ ] **One failure of three:** the other two are published, the exit code is 0, the summary says `1 failed`, and
      exactly one failure notification exists.
- [ ] **A throwing notifier is swallowed and reported:** bind a double of `NotifyScheduledBlogPostPublishFailed` that
      throws; the loop continues, the remaining posts are published, exit code 0, the exception reached `report()`.
      *Mutation:* remove the command's own `try/catch` around the call.
- [ ] **A clean run and a `--dry-run` send nothing** (`Notification::assertNothingSent()`).
- [ ] **Class 2 through the command:** the throwing-listener case yields one `announce` notification and the post is
      `Published`; a second tick sends nothing more.
- [ ] The existing assertions (the `N failed; see the log.` line, the `report()`, exit 0) still hold **unchanged**.

### Explicitly not tested

Real SMTP and a real queue worker (`QUEUE_CONNECTION=sync` and the `array` mailer stand in) · a real database outage
(**D-12** states what is and is not guaranteed instead) · cron · 0063's screens (the link tests stop at the route
name) · the queued job's own `failed()` handling · the bell rendering of this type (**H-3**).

## Expected outcome

- A due `Scheduled` post whose write fails stays `Scheduled`, is retried every minute, and its creator gets **one**
  dashboard notification and **one** email (then at most one reminder per 24 hours while it keeps failing).
- A post whose synchronous listener throws after the write stays `Published`, is not retried, and its creator gets one
  notification and one email saying the post is live but the follow-up could not be sent.
- If the creator is unreachable the `blog.edit` administrators get the same message; if nobody is reachable, one log
  line with the post id and the stage and nothing else.
- The sweep's loop, its exit code (0) and 0064's `N failed; see the log.` line are unchanged; a failure of the notice
  itself is `report()`ed and never breaks the run.
- `blog_posts.created_by` exists, is stamped by `CreateBlogPost` from the acting user, and is `NULL` for every legacy
  row.

## Acceptance criteria

- [ ] `blog_posts.created_by` exists as a nullable UUID FK to `users.id`, `ON DELETE SET NULL`, with no hand-written
      index; the table has exactly five indexes.
- [ ] `created_by` is written only by `CreateBlogPost`, from `Auth::id()` after `authorize()`; it is not fillable and
      `UpdateBlogPost` never changes it.
- [ ] A failed sweep write reports stage `publish`; a throwing listener reports stage `announce`; the two are told
      apart by one re-read of the post; a missing, soft-deleted, drafted or no-longer-due post reports nothing.
- [ ] The notification's `data` is exactly `blog_post_id`, `title`, `stage`; **no exception message, class, SQL or
      stack frame appears in the notification data, the email or any log line this story writes.**
- [ ] The creator is the only recipient when reachable (exists, `Active`, has an email, `can('blog.edit')`); otherwise
      the live `Active` `blog.edit` holders (Super Admin excluded); otherwise nobody and one privacy-safe log line.
- [ ] Recipients are resolved **before** the dedup key is claimed; the key is released if the send throws.
- [ ] A post that keeps failing yields at most one notification per (post, stage, `published_at`) per 24 hours.
- [ ] The email has a subject naming the post, one line per stage, an "Edit post" button and an "All posts" link, in
      the app locale, English and Spanish key-for-key; a hostile title cannot create a link, an element or a second
      subject line.
- [ ] The notification is `ShouldQueue` with channels `['database', 'mail']`.
- [ ] `PublishScheduledBlogPost` and `ScheduledBlogPostPublished` are byte-for-byte unchanged; the command's only diff
      is the guarded call in its `catch`.
- [ ] Nothing in the notifier can throw into the sweep, and the exit code stays 0.
- [ ] The hand-offs (0064's item 7, 0065, 0057, 0063, 0078, 0066/0067) are recorded.

## Definition of Done

- [ ] Tests written and green, plus the **full** existing suite in a single isolated run, per
      [contracts.md](../../docs/contracts.md)'s Full Test Suite Gate Rule.
- [ ] All **three** quality gates run **unscoped**, each result recorded explicitly *including any that was not
      run*: `php artisan test` (not `--filter`), `vendor/bin/pint --format agent` (not `--dirty`), and **Larastan
      level 7** (`vendor/bin/phpstan analyse`). A record naming two of three is a record of two gates.
- [ ] **Every test named "proven able to fail" was mutated once** (the mutations listed in *Tests to perform*) and each
      red result recorded, including the **key-release**, **no-recipient-no-claim**, **privacy marker**, **hostile
      title** and **command-containment** mutations, which have no other guard.
- [ ] **Every claim this file makes about 0064's and 0063's code is re-verified against `HEAD`** before implementation:
      the command's `catch` (still `report()` → count → continue → exit 0), the action's contract, the event's
      property name, and 0063's route names and `{blogPost}` parameter. A name in this file is a reading aid, not a
      locator.
- [ ] `php artisan migrate` and `php artisan migrate:rollback --step=1` are run against a scratch database and
      `php artisan db:table blog_posts` is recorded (five indexes, the FK's `set null` rule).
- [ ] Code reviewed (code-reviewer). **Point the review at:** the order *resolve → claim → send → release*; that the
      notifier receives an id and never the exception; that `created_by` has no writer but `CreateBlogPost`; that the
      command's diff is the `catch` only.
- [ ] No security findings (appsec-auditor). **Point the audit at:**
      1. **Privacy of the failure detail** — no exception text, class or SQL in the email, in `notifications.data`, in
         the `jobs` payload or in any log line this story writes (the original exception is `report()`ed by the
         command as 0064 already does; that operator log is not a new sink).
      2. **Recipient resolution** — no title is disclosed to a person without `blog.edit`; a soft-deleted user's
         obfuscated address is never mailed; no caller-supplied input reaches the recipient query.
      3. **Markdown/HTML injection through the title** — the title is user content in the subject and the body.
      4. **The ungated system action** — that it has exactly one caller (the command), reads no actor, and that
         condition two of the [ungated-write rule](../../docs/architecture/authorization/domain-invariants.md#a-system-triggered-write-may-be-ungated--autocancelfullyrefundedorder-and-the-three-conditions-that-make-it-safe)
         is "deployment access", exactly as for 0064.
- [ ] Documentation updated (docs-keeper), one footer line per doc, base fetched first:
  - [`database/schema.md`](../../docs/database/schema.md) — the ER diagram: a `uuid created_by FK` line in the
    `BLOG_POSTS` block and `USERS ||--o{ BLOG_POSTS : "created_by (nullable)"` beside `USERS ||--o{ MEDIA :
    "uploaded_by (nullable)"`. Every new column and relationship is diagrammed.
  - [`database/schema-blog.md`](../../docs/database/schema-blog.md#blog_posts) — the `created_by` column row,
    the `nullOnDelete` reasoning with the soft-delete caveat linking
    [the `media.uploaded_by` note](../../docs/database/schema-products/sales-regions-and-media.md#uploaded_by-and-the-soft-delete-interaction--read-this-before-fixing-the-fk),
    "no index beyond the FK's own", "no backfill", and **the index list corrected from "exactly four" to five**.
  - [`database/migrations/delete-behaviour-and-vendored.md`](../../docs/database/migrations/delete-behaviour-and-vendored.md)
    — a one-line instance of the "null" rule; and the *explicit table name* rule's next instance in
    [`uuid-primary-keys.md`](../../docs/database/migrations/uuid-primary-keys.md).
  - [`database/schema-other/notifications.md`](../../docs/database/schema-other/notifications.md) — the new type, its
    three-key payload, its channels and its dedup key.
  - [`testing/backend/scheduled-commands.md`](../../docs/testing/backend/scheduled-commands.md) — how to test the failure
    notice (the two failure drivers, the cache-key assertions).
  - [`architecture/authorization/domain-invariants.md`](../../docs/architecture/authorization/domain-invariants.md) —
    the notifier as an ungated collaborator of the sweep; the cross-reference.
  - [`conventions/directory-structure/`](../../docs/conventions/directory-structure.md) — `app/Enums/` and
    `app/Notifications/` each gain a class; `app/Actions/Blog/` gains another ungated one.
  - [`README.md`](../../README.md) — the queue-worker note (line 176's paragraph) gains this notification: **without a
    worker neither the bell entry nor the email is produced** (**D-5**).
  - `ai-spec/tasks-map.md` and `ai-spec/tasks-status.json` regenerated in the same pass (this file's creation, its
    `depends_on` 0064/0063, and its `conflict_risk_with` 0065).
- [ ] Hand-offs recorded (see below).
- [ ] Acceptance criteria met.

## Documented functional decisions

### D-0 — The owner chose "report it in this story" over "the 0065 listener catches its own failures"

Story 0064's hand-off item 7 asked 0065 to *decide whether the listener catches and reports its own failures so a
notification problem does not read as a publish problem*, and **recommended** that it does. **The human owner's
instruction supersedes that recommendation:** when the sweep fails, the creator must be told, by dashboard notification
and by email. Told *what*: for the announcement failure that means the failure must be **observable** where the sweep
can see it.

The two designs cannot both hold. A listener that catches its own exception leaves the sweep with nothing to detect;
this design needs the exception to **propagate** out of `ScheduledBlogPostPublished::dispatch()` (it already does, and
0064 pins it: *"a listener that throws surfaces after the write and does not undo the publication"*). So:

- **0065's listener must NOT swallow its errors** — an exception from `NotifyBlogPostPublished` propagates out of the
  listener. **0064's hand-off item 7 is amended accordingly** and **0065's listener contract is amended** (see the
  hand-offs).
- The trade-off the recommended design was avoiding is **accepted by the owner**: a notification problem *does*
  read as a per-post `failed` in the sweep's summary. That reading is now correct and useful, because it is what
  triggers this story's message.
- **Rejected alternative:** the listener swallows and reports its own error (0064 item 7's recommendation). It keeps
  the sweep's counts clean but leaves the creator of a post that went live with no announcement and no signal, which
  is precisely the gap the owner asked to close.

### D-1 — Both failure classes, through one capture site: the command's existing `catch`

The command already has the only place both failures surface — a per-post `Throwable` catch that `report()`s, counts
and continues. This story hooks **there**, once, for both classes. Class 1 (`publish`) is the write throwing; class 2
(`announce`) is a synchronous listener throwing after the write (**V-2**).

`PublishScheduledBlogPost` and `ScheduledBlogPostPublished` are **not changed**: their contract, the
dispatch-after-write guarantee, the conditional `UPDATE` and the at-most-once property (0064 **D-7**, **D-12**) all stay.
**Rejected:** (a) a domain event or exception wrapper around the two failures, which would force the action to `catch`
and rethrow — a second behaviour in a class whose whole value is having one; (b) class 1 only, which splits one
concern ("a scheduled post did not complete") across two stories and leaves the announcement failure invisible again.

**Class 2 cannot occur in production until story 0065 ships a listener** (**V-14**): today the event has no listener.
It is proven with a test-registered throwing listener and needs no 0065 code.

### D-2 — The two classes are told apart by one re-read of the post, and that read lives in the notifier

**What the command knows.** In the `catch` it holds only a post id and a `Throwable`. **What the notifier reads:** the
post, once, scoped (so a soft-deleted or missing post is `null`). The same read supplies the **status** (which decides
the stage) and the **title** (which goes in the message), so there is no second read to disagree with the first:

| Post as re-read | Stage | Why |
| --- | --- | --- |
| `Scheduled` **and** `published_at <= now()` | `publish` | the write did not happen; it will be retried |
| `Published` | `announce` | the write happened; a later step threw |
| `Scheduled` but **not yet due** | nothing | an editor rescheduled it between the failure and the re-read |
| `Draft`, missing, or soft-deleted | nothing | there is nothing (still) to report, and a mail about a deleted post is the worse mistake |

**Facilitator refinement, recorded because it sharpens the wording of the brief.** The brief says *"the command tells
them apart by re-reading the post in the catch"*. Placing the re-read **inside the notifier** keeps the command's diff
to one guarded call, and it means a failing re-read is automatically inside the notifier's own `try/catch` (**OQ-2**).
The command remains the single **capture** site; the classification is one private method in the notifier.

**Documented limits (accepted, tested):**

- A **concurrent manual publish** between the failed write and the re-read reads as `announce` — the wrong message,
  for a post that is nevertheless live. The window is a fraction of a second.
- **Any throw after the write** (including the action's own re-read after the `UPDATE`) reads as `announce`. The message
  says a follow-up failed; that is true enough and the post is live.
- If the **re-read itself throws**, the failure is `report()`ed and the notice is skipped (**OQ-2**). The "default to
  `publish`" branch is nearly unreachable and is not worth building: the same read supplies the title, so a failed read
  could not even produce a useful message.

### D-3 — `NotifyScheduledBlogPostPublishFailed` is a new ungated system action, and its order is the contract

A collaborator of the sweep, in `app/Actions/Blog/`, taking `string $blogPostId`. It carries the exemption in its own
docblock, in the manner of `PublishScheduledBlogPost`: **no `Gate`, no `Auth`, no `request()`, no policy call.** The
recipient predicate is data (permissions, status), not an authorization decision about an actor. It **never receives
the exception** — a class that cannot see the exception text cannot leak it.

The steps, in this order, all inside `try/catch` with `report()` so the sweep and its exit code are never affected:

1. **Load the post** (scoped) and classify it (**D-2**); nothing to report → return.
2. **Resolve the recipients** (**D-8**); none → one log line (post id and stage only) → return.
3. **Claim the dedup key** (**D-6**); already claimed → return.
4. **Send**; if the send throws, **release the key** and let the outer catch `report()`.

**The order is `backend-qa`'s correction and is adopted** (**OQ-4**). The naive order (claim, then resolve) silences a
post for 24 hours whenever the recipient set is empty, or whenever the queue push fails, and nobody ever learns the
post is stuck. Resolving first costs a recipient query per tick for a *stuck* post that is already inside its 24-hour
quiet period (a few queries per minute per stuck post, the same order as the sweep's own selection); a
`Cache::has()` shortcut before resolving was considered and **not adopted**, because a two-step check invites the race
`Cache::add()` exists to remove.

Condition two of the ungated-write rule is stated, not claimed: the class's only caller is the sweep command, so what
restricts it is deployment access (as for 0064 **D-5**).

### D-4 — The notification: primitives in, three keys out, never a word of the exception

`App\Notifications\ScheduledBlogPostPublishFailed` — named as a statement of fact like `CustomerCreated`, with no
`Notification` suffix. Constructor: `string $blogPostId`, `?string $title`, `BlogPostPublishFailureStage $stage`.

- **`data` is exactly `blog_post_id`, `title`, `stage`.** `stage` is the enum's string value so a future bell arm can
  branch without knowing the class. **No `type` discriminator** (Laravel writes the FQCN into `notifications.type`),
  **no message, no exception class, no SQL, no recipient echo.** The original exception is `report()`ed by the command
  as 0064 already does — that is the operator's log, not a second copy in a user-facing store.
- **`title` is a frozen snapshot**, exactly the reasoning of [0065's D-4 and D-4a](0065-blog-post-published-notification-backend.md):
  a rename after the failure is a new fact. Read in **exactly one place** (the notifier), so 0078 changes one line to
  `translated('title')` in the store default language.
- **No `SerializesModels`, no model in the constructor** (contrast `OrderCreated` and `CustomerCreated`, whose
  `SerializesModels` reasoning is about a *future* queued form): this one **is** queued, so it takes primitives, which
  serialise exactly and cannot be rehydrated stale or against a row deleted in between.
- **Privacy caveat, stated:** `notifications.data` keeps the title with **no erasure path**, the same position as
  0043's customer name.

### D-5 — Queued, and the consequence is that **both** channels wait for a worker

`ShouldQueue`. An outbound SMTP call must not run inside the sweep, which holds a `withoutOverlapping(5)` mutex; the
precedent is `PendingEmailVerification` (queued, an outbound send) against `UserInvitation` (synchronous, because its
constructor holds a credential — a title is not one). **Rejected:** synchronous, like `UserInvitation` (**OQ-10**).

**Verified consequence (V-6): Laravel queues one job per channel per recipient for a `ShouldQueue` notification, so
the *database* channel is deferred too.** Without a running worker (`QUEUE_CONNECTION=database` in
`.env.example`), **neither the bell entry nor the email is produced**, and nothing says so. This is the same trap
README.md already documents for the email-change notification. It is recorded in the docs (see the DoD) and is the
reason **OQ-10** lists the split alternative (the database row synchronously, only the mail queued). The `jobs` payload
holds a post id, a title and a stage — no credential and no exception.

### D-6 — Dedup for class 1: one atomic cache key per (post, stage, `published_at`), 24-hour TTL, no schema

A failing post is retried **every minute** (0064 **D-3**), so an unguarded notice would mail the creator 1,440 times a
day. The guard is
`Cache::add("blog-publish-failed:{id}:{stage}:{published_at->timestamp}", 1, now()->addDay())`:

- **Atomic** on the database store (`DatabaseStore::add()` is `insertOrIgnore` on the primary-keyed `cache.key`, **V-5**).
- **`published_at` in the key**: rescheduling starts a new episode. **Rescheduling to the very same instant within 24 h
  stays suppressed** — documented, accepted.
- **24-hour TTL**: a stuck post reminds at most once a day (**OQ-9**).
- **A cache flush can cause one duplicate.** Accepted.
- **If the cache write throws: skip and `report()`.** Failing open is impossible without the cache, because the
  alternative is a notice on every tick; stated rather than hidden.
- The **stage** is in the key, so an `announce` after a `publish` in the same episode is reported.
- For `announce` the post is never retried, so the key is only a harmless guard against a double capture.

**Rejected, each with its reason (database-expert):**

| Alternative | Why not |
| --- | --- |
| A `publish_failed_at` column on `blog_posts` | must be reset in every writer that changes status or schedule, plus `#[Fillable]`, factory, ER — schema and a cross-cutting invariant for a transient flag |
| A `blog_post_publish_failures` table | a table, a model, a factory, an ER entry and a retention job for one boolean |
| Querying `notifications` for a prior row | `data` is unindexed `TEXT`; a user deleting a notification would reset the episode; it cannot cover a mail-only outcome |
| Per-run de-duplication only | does not stop the every-minute repeats, which is the actual problem |

**No "resolved / published after retry" notification** (**OQ-7**).

### D-7 — `blog_posts.created_by`: nullable, `nullOnDelete`, immutable, unbackfilled

The creator is what makes "tell the person who can fix it" possible; it is the **first** author attribution on a blog
post (0065 D-4 recorded *"there is none"*).

- **Name and precedent:** `created_by`, following `media.uploaded_by` and `refunds.refunded_by` (both in
  [docs/database](../../docs/database/schema-products/sales-regions-and-media.md#uploaded_by-and-the-soft-delete-interaction--read-this-before-fixing-the-fk)).
- **`constrained('users')` is mandatory**, not stylistic: the column name does not match the table, and Laravel would
  infer a `created_bies` table.
- **`nullOnDelete()`, not restrict:** a post is meaningful without its creator (unlike `refunds.refunded_by`, a
  financial record). **It will essentially never fire**, because `users` is soft-deleted and a soft delete is an
  `UPDATE`: the column **stays populated** and `creator()` resolves `null` through the `SoftDeletingScope`. The
  notifier therefore treats "null relation" as "no reachable creator" for **both** causes (a `NULL` column and a
  trashed user) and **nothing branches on the distinction**; both fall back (**D-8**).
- **No extra index:** InnoDB creates the FK's own, and nothing filters by creator.
- **No backfill.** Legacy rows stay `NULL`; a guess would invent data. The migration docblock says so.
- **Not `#[Fillable]`; written only by `CreateBlogPost`** through its `forceCreate()` literal, from the authenticated
  actor after `authorize()`, never from a parameter (the `refunded_by` rule). **Immutable:** `UpdateBlogPost` never
  writes it, so it means *creator*, never *last editor*.
- **No `updated_by`** (**OQ-8**).
- **Position:** `->after('published_at')`. 0078's migration drops `title`/`slug`/`body` and re-adds them on `down()` with
  `->after('blog_category_id')`, so there is no column-order collision. **Only migration timestamp order matters:**
  this migration must sort **before** 0078's, and whichever story lands second rebases the docs/ER edits.
- **`BlogPost` PHPDoc:** `@property string|null $created_by`, `@property-read User|null $creator`.

### D-8 — Recipients: the reachable creator, else the live `blog.edit` holders

One recipient set for **both** channels (the bell and the email go to the same people).

1. **The creator, if reachable:** the relation resolves (exists, not soft-deleted), `status` is `UserStatus::Active`,
   the email is non-empty, and `->can('blog.edit')`. **A Super Admin creator is reachable** through `Gate::before`.
2. **Otherwise the fallback:** `User::permission('blog.edit')`, live (the `SoftDeletingScope`), `Active`, with an email.
   **The Super Admin is excluded** — `permission()` is a data query and the bypass grants no rows — consistent with
   0043/0046/0065 **D-1** (**OQ-6**).
3. **Otherwise nobody:** one `Log::warning` carrying the post id and the stage, and nothing else.

**Why `blog.edit`, not `blog.view` — and why this differs from 0065 (`blog.view` holders).** 0065 tells everyone who
may *look* at the blog that a post went live: awareness, deliberately wide. This message is an action item: **the
people who can fix it**. The email's button opens the editor, which needs `blog.edit` (0063 authorizes `update` inside
the editor); telling a view-only administrator about a failure they cannot act on is noise, and a title disclosed for
no purpose. `backend-qa` found the gate; **a creator who lost `blog.edit` falls to the fallback** (**OQ-3**).
**The route itself is gated `can:blog.view`** (0063 **D-3**), and the catalog has no rule that `edit` implies `view`
(**V-11**): an editor with `edit` but not `view` gets the message and a 403 on the link (**OQ-12**).

### D-9 — The email: two lines by stage, the title escaped, never a word of the exception

Copy in `lang/{en,es}/notifications.php` under a new `mail.blog_post_publish_failed.*` group. **Convention note:** the
other mail notifications keep their copy in the domain file (`users.invitation.*`, `users.email_change.*`), while
`notifications.php` today holds the bell's copy (0057). The owner's brief places this group in `notifications.php`,
which is where a future bell arm for this type will read its summary too; recorded so a reviewer does not read it as an
inconsistency.

- **Subject:** `Scheduled post could not be published: :title` (an `untitled` key substitutes for a `null` title).
- **One line per stage.** `publish`: *the post could not be published automatically at its scheduled time; it is still
  scheduled and the system keeps retrying; there will be no further email about this post for 24 hours.* `announce`:
  *the post was published, but the follow-up notification to administrators could not be sent.* Neither line contains
  the reason; the wording says **what failed** at the level an editor can act on, which is what the owner asked for.
- **Buttons:** the primary action **"Edit post"**, and a plain **"All posts"** link (**D-10**).
- **Locale:** the **app locale** (`config('app.locale')`). `User` has no locale preference and a queued notification runs
  with no request; stories [0066](0066-admin-ui-locale-preference-backend.md) and
  [0067](0067-admin-ui-language-switcher-ui.md) introduce one, and then this becomes the recipient's preference
  (future, not this story).
- **The title is user content in the subject and the body — escape it (`backend-qa`).** Verified against the framework
  (**V-7**): the mail template HTML-encodes `<` and `>` but **not Markdown syntax**, and the optional secured encoding is
  off, so `[x](https://evil.example)` inside a `->line()` renders as a **live link inside a trusted email**. Requirements
  (implementation is `backend-expert`'s): collapse whitespace and strip control characters so a newline cannot break the
  single-line subject; escape Markdown metacharacters in the **body**; cap the subject length; never wrap the title in
  `HtmlString`/`Htmlable`. A `null`/blank title uses the fallback wording. The rendered-HTML `href` set is pinned by test.

### D-10 — The links are `url(route('blog-posts.edit', …))` and `url(route('blog-posts.index'))`, so this story is blocked on 0063

Absolute URLs (`url(route(...))`), because an email is read outside the app. The routes and their parameter
(`{blogPost}`) belong to story [0063](done/0063-blog-posts-list-editor-ui.md) (**D-3**) and do not exist today (**V-4**), so
0064b is **blocked** on it (**OQ-1**). The bell (story 0057) renders this notification type through its permanent
`default` arm — *"New notification"*, **no link** — until it gains an arm; that is a follow-up (**H-3**), not this story.

### D-11 — Command integration: the `catch` only

The command's `catch` gains one call, **after** the existing `report()` and count, inside its **own** `try/catch`:

```php
} catch (Throwable $exception) {
    $failed++;
    report($exception);

    try {
        $notifyFailure($id);              // resolved through handle()'s method injection
    } catch (Throwable $notifierFailure) {
        report($notifierFailure);         // never abort the loop, never change exit 0
    }
}
```

The notifier already contains everything, so this outer guard is belt and braces — but it is the one a test can force
(with a throwing double), and it is what makes "a notifier failure is `report()`ed and never aborts the loop or changes
exit 0" a **tested** property instead of a promise. The selection, the `--dry-run` path, the summary and the exit code
are unchanged; a `--dry-run` never reaches the `catch`.

**Pre-existing wart, not this story's:** the run summary counts `published` only on a non-null return, so a class-2 post
prints `Published 0 …` though it is live (and `1 failed`). Recommended deferred (**OQ-5**).

### D-12 — What is and is not guaranteed when the database is unhealthy

Stated precisely, so nobody reads the design as stronger than it is.

- **Guaranteed, always:** the notifier never throws into the sweep; every error it meets is `report()`ed; the loop
  continues and the exit code stays 0.
- **A total database outage** is caught by the command's selection query first (it throws before any post is processed,
  pre-existing 0064 behaviour), so the notifier is not reached at all.
- **An outage that begins mid-run:** the per-post write fails, the notifier's re-read fails, and the notice is skipped
  and `report()`ed. **Nothing is delivered** — resolving recipients also needs the database, so even the mail (which
  would otherwise be independent of it) cannot be addressed.
- **Under the production defaults** (`CACHE_STORE=database`, `QUEUE_CONNECTION=database`) the dedup write, the
  `notifications` insert and the `jobs` insert share that fate. With the cache and queue on a store that is *not* the
  failing database, a class-1 failure caused by a single row (a lock timeout, a constraint) is fully reported, which is
  the case this story exists for.
- **Volume:** the once-per-key-per-day guard bounds a stuck post. A *mass* failure (say a bad deploy failing 500 due
  posts on one tick) still produces up to *posts × recipients* notices on that tick; a per-run cap is **OQ-11**,
  recommended against.
- **A partial send** (the push throwing after some recipients were queued) releases the key, so the next tick may notify
  those recipients again. Accepted: a duplicate is better than a silent post.

### D-13 — Sequencing and neighbours

Depends on **0064** (this branch) and is **blocked on 0063** for the links. **Independent of 0065 for class 1**; class 2
is proven with a test-registered listener until 0065 ships, after which **0065's own end-to-end test should be extended**
to assert a failing `NotifyBlogPostPublished` produces this story's `announce` notice (**H-2**). 0078 later changes the
single title read to `translated('title')` in the store default language; no shared helper is introduced, so there is
exactly one place to change (**D-4**). Numbering `0064b`: found while closing 0064, and the ordering rule holds.

### D-14 — Gherkin for the scheduler follows 0064's D-14

The `When` names *the publication scheduler*; the `Given` carries the blog editor whose decision created the state; the
failure is a fact of the world, never a mechanism. The three scenarios about the creator being *recorded* are ordinary
actor-driven scenarios. No new convention is introduced.

## Scope fences: what this story must NOT do

- **No edit to `PublishScheduledBlogPost`, `ScheduledBlogPostPublished`, the schedule entry, `routes/console.php` or
  `bootstrap/app.php`.** The command's diff is the `catch` and one method-injected parameter.
- **No listener, no observer, no model event, no `boot()` hook.** The capture is the command's `catch`.
- **No dedup column, table or model.** The guard is one cache key (**D-6**).
- **No `updated_by`, no backfill of `created_by`, no `created_by` in `#[Fillable]`, no write to it from `UpdateBlogPost`.**
- **No route, Livewire component, Blade view, `config/modules.php` entry or browser test**, and no stub of 0063's routes.
- **No bell arm, no edit to 0057's component** (**H-3**).
- **No exception message, exception class, SQL, stack frame or reason string in the notification data, the email or any
  log line this story writes.** The notifier does not receive the exception.
- **No `Gate`, `Auth` or `request()` in the notifier**, and no `withTrashed()` anywhere (the `SoftDeletingScope` is what
  keeps a deleted post and a deleted user out).
- **No "resolved / published after retry" notification**, no per-recipient locale, no digest, no per-run cap (their OQs).
- **No Super Admin in the fallback set** unless **OQ-6** is reversed — and then for 0043, 0046 and 0065 together.
- **No change to 0065's or 0064's task files from this file** — the hand-offs below are recorded for the orchestrator.

## Dependencies, risks and open questions

### Verified environment findings

Read against this worktree (`vendor/` present) at `HEAD` = `d1e507f`; nothing here was run.

- **V-1 — The command's catch.** `app/Console/Commands/PublishScheduledBlogPosts.php`: a per-post
  `catch (Throwable $exception) { $failed++; report($exception); }`, a summary counting `published` only on a non-null
  return, exit code `self::SUCCESS` in every branch.
- **V-2 — A throwing listener propagates out of the action.** `tests/Feature/Blog/PublishScheduledBlogPostTest.php`
  (`'a listener that throws surfaces after the write and does not undo the publication'`): the post stays `Published`, the
  exception surfaces, a second call returns `null`. `ScheduledBlogPostPublished::dispatch()` is synchronous.
- **V-3 — There is no creator column.** `2026_09_24_162607_create_blog_posts_table.php` has none, and
  `BlogPost`'s `#[Fillable]` is `['title', 'body', 'blog_category_id', 'status']`. `CreateBlogPost` authorizes with
  `LogRefusedPrivilegedAttempt::authorize()` (default actor `Auth::user()`) before its `forceCreate()`.
- **V-4 — 0063's routes are not shipped.** `routes/` holds `blog-categories.php` and `blog-tags.php` but no
  `blog-posts.php`. 0063's **D-3** defines `blog-posts.index` (`blog/posts`), `blog-posts.create` and `blog-posts.edit`
  (`blog/posts/{blogPost}/edit`), all gated `can:blog.view`.
- **V-5 — `Cache::add()` is atomic on the database store**, and the array store honours the test clock.
  `DatabaseStore::add()` returns `false` if a live value exists, else `insertOrIgnore` on the primary-keyed `key`;
  `ArrayStore::get()` compares against `Carbon::now()`, so `Carbon::setTestNow()` moves the TTL. (Phase 3 asserts the
  second point rather than trusting this reading.)
- **V-6 — `ShouldQueue` queues every channel.** `NotificationSender::send()` routes a `ShouldQueue` notification to
  `queueNotification()`, which loops `via()` and dispatches a `SendQueuedNotifications` job **per channel**, database
  included.
- **V-7 — Markdown syntax in a mail line is not neutralised.** `Markdown::$withSecuredEncoding` defaults to `false` and
  nothing in `app/` or `bootstrap/` calls `withSecuredEncoding()`. `SimpleMessage::formatLine()` collapses newlines in a
  line, but nothing escapes Markdown. Phase 3 proves the mitigation by test, not by this reading.
- **V-8 — Test and default environments.** `phpunit.xml`: `CACHE_STORE=array`, `MAIL_MAILER=array`,
  `QUEUE_CONNECTION=sync`. `.env.example`: `QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `MAIL_MAILER=log`.
- **V-9 — A soft-deleted user's email is obfuscated.** `App\Models\User::delete()` rewrites `email` before the soft
  delete (its docblock); `User` uses `SoftDeletes`, so `User::query()` excludes trashed rows.
- **V-10 — There is no locale mechanism yet.** `config/app.php` `locale` is `env('APP_LOCALE', 'en')` and there is no
  `setLocale` in `app/`.
- **V-11 — `blog.edit` and `blog.view` are independent, flat abilities** (`ACTIONS = ['view','create','edit','delete']`,
  the policies "flat, tier-free"); no rule makes `edit` imply `view`.
- **V-12 — The FK precedents exist:** `media.uploaded_by` (`foreignUuid(...)->nullable()->constrained('users')->nullOnDelete()`)
  and `refunds.refunded_by`, both documented under `docs/database/`.
- **V-13 — `BellTest` already asserts** that every `en/notifications.php` key exists in `es/notifications.php`, one
  direction only.
- **V-14 — 0065 has not shipped.** `App\Actions\Blog\NotifyBlogPostPublished` is a no-op placeholder and `app/Listeners/`
  holds three listeners, none for this event.
- **V-15 — `blog_posts` will have five indexes** once the FK exists; `docs/database/schema-blog.md` says "exactly four".

### Dependencies

| Depends on | State | Why |
| --- | --- | --- |
| [0064](done/0064-scheduled-post-auto-publish-backend.md) — scheduled auto-publish | **hard, in progress (this branch)** | Owns the command, the action, the event and `FailingBlogPostWrites`. Hooked into, not changed |
| [0063](done/0063-blog-posts-list-editor-ui.md) — blog posts list and editor | **hard, `new`** | Owns `blog-posts.index` and `blog-posts.edit`. **OQ-1** |
| [0043](done/0043-customers-new-customer-notification-backend.md) — notifications table | **shipped** | Owns `notifications` (`uuidMorphs('notifiable')`) |
| [0061](done/0061-blog-posts-core-crud-backend.md) — blog posts core CRUD | **shipped** | `BlogPost`, `CreateBlogPost`, the factory |
| [0065](0065-blog-post-published-notification-backend.md) | **not a dependency for class 1; must be amended (H-1, H-2)** | Its listener must not swallow errors (**D-0**) |
| [0057](done/0057-notification-bell-ui.md) — the bell | **not a dependency** | Renders this type generically until it gains an arm (**H-3**) |
| [0078](0078-translatable-content-retrofit-blog-posts-backend.md) | **later** | Changes the single title read; its migration must sort after this one |
| [0066](0066-admin-ui-locale-preference-backend.md) / [0067](0067-admin-ui-language-switcher-ui.md) | **later** | Replace the app locale with the recipient's preference |

### Risks

- **R-1 — Silent no-op without a queue worker.** Both channels are queued (**D-5**, **V-6**); with `QUEUE_CONNECTION=database`
  and no worker nothing is produced and nothing says so. *Mitigation:* documented in README.md and the docs; **OQ-10**
  lists the split. No application-level detection exists.
- **R-2 — Silent no-op without a scheduler cron** is 0064's **R-7**, unchanged; a stuck-post notice depends on the sweep
  running at all.
- **R-3 — The recipient predicate never matches in a store with no `blog.edit` holder and a gone creator.** The log line is
  the only trace; asserted.
- **R-4 — A title injection in an email** (**D-9**). The framework does not protect against Markdown syntax; a test pins the
  rendered `href` set.
- **R-5 — A wrong stage under a race** (**D-2**). Accepted and tested.
- **R-6 — A cache flush or a partial send can duplicate one notice** (**D-6**, **D-12**). Accepted.
- **R-7 — An editor with `edit` but not `view` gets a 403 on the link** (**OQ-12**).
- **R-8 — Merge order with 0078.** Both touch `blog_posts` and its docs; the migration timestamps must sort this story's first.
- **R-9 — The `notifications` type is invisible to the bell** until 0057 gains an arm (**H-3**); the row exists and is
  counted as unread, and renders as *"New notification"*.
- **R-10 — `title` is stored with no erasure path** in `notifications.data`, as 0043's name is (**D-4**).
- **R-11 — A queued payload holds the title in plaintext in `jobs`** until processed. Not a credential; recorded.

### Open questions

**OQ-1 — Block on 0063, or link to `dashboard` now?** *(recommended: block.)* **(a) Block on 0063 _(recommended)_** — the
owner asked for *"a link to edit it or to the posts page"*, and those exist only in 0063; building against
`route('blog-posts.edit')` before the route exists throws at render time. **(b)** link to `dashboard` now and switch to the two
routes when 0063 ships — works today but is temporary code, a test that must be rewritten, and it does not meet the owner's wording.

**OQ-2 — If the re-read in the notifier throws.** **(a) Report and skip _(recommended)_** — the same read supplies the title
and the status, so a failed read cannot produce a useful message anyway; the "default to `publish`" branch would mail a
title-less notice. **(b)** default to `publish` with a null title — a message with no post name is not what the owner asked for.

**OQ-3 — Which permission makes a creator reachable?** **(a) `blog.edit` _(recommended)_** — the button opens the editor; a creator
who lost edit falls to the administrators who can act. **(b)** `blog.view` with the edit button shown conditionally — keeps the
creator informed but the action they are told to take may be one they cannot do.

**OQ-4 — Release the dedup key when the send throws?** **(a) Release _(recommended)_** — one `Cache::forget` in the failure branch
prevents a queue-push failure from silencing a post for 24 hours; the cost is a possible duplicate to already-queued recipients.
**(b)** accept 24 hours of silence — simpler, but the failure this whole story exists to surface then hides itself.

**OQ-5 — The `Published 0` summary for a class-2 post.** **(a) Defer _(recommended)_** — a pre-existing 0064 wart, a one-line
count change with its own tests, and outside the owner's ask. **(b)** fix here by counting a post the read shows as `Published`.

**OQ-6 — Should the Super Admin be in the fallback set?** **(a) No _(recommended)_** — consistent with 0043/0046/0065 **D-1**; the
Super Admin who created a post is still reachable as its creator. **(b)** yes — if reversed it must be reversed for all four.

**OQ-7 — A "resolved / published after retry" notification?** **(a) No _(recommended)_** — the reminder stops when the post goes
live, and a positive follow-up is a second notification type and a second dedup story. **(b)** yes, sent by the sweep on the first
successful publish after a reported failure — needs state to know one was reported.

**OQ-8 — Add `updated_by` and notify the last editor?** **(a) No, creator only _(recommended)_** — a second column, a writer in
`UpdateBlogPost` and a semantics question ("last editor" of what?); the fallback covers an unreachable creator. **(b)** add it.

**OQ-9 — The dedup TTL.** **(a) 24 hours _(recommended)_** — at most one reminder a day for a stuck post. **(b)** 1 hour — noisier
for a fault that needs a human. **(c)** once per episode (no TTL, or a long one) — a permanently stuck post is never mentioned again.

**OQ-10 — Queued or synchronous mail (and the database row)?** **(a) Queued, both channels _(recommended)_** — the owner's
agreed design; keeps SMTP out of the mutex-holding sweep. **(b)** synchronous, like `UserInvitation` — no worker needed, but an
SMTP timeout stalls the sweep for up to the mutex expiry. **(c)** split: `Notification::sendNow()` for the database row and a queued
mail — the bell works without a worker, at the cost of two send calls and one class with two behaviours.

**OQ-11 — A per-run cap on notifications during a mass failure?** **(a) No _(recommended)_** — the once-per-day key bounds a stuck
post and a cap would silently drop the notices for the posts past it. **(b)** cap at N per run with one summary line for the rest.

**OQ-12 — A recipient who holds `blog.edit` but not `blog.view` gets a link that 403s.** **(a) Accept _(recommended)_** — they
still learn which post failed; a role with `edit` and no `view` is a misconfigured role. **(b)** require both abilities for both
the creator and the fallback — one more predicate in each of two places.

## Resolved in the debate

1. **Which failures?** Both, through one capture site (**D-1**).
2. **Who tells the two apart, and how?** One re-read of the post, in the notifier (**D-2**).
3. **Where does the dedup state live?** One atomic cache key, no schema (**D-6**).
4. **Is there a creator to notify?** Not yet: this story adds `created_by` (**D-7**).
5. **Does 0065's listener catch its own failures?** No (**D-0**).

## Hand-offs: other stories and files this story changes the contract of

Recorded here, **not applied from this file**; the orchestrator or the owning phase amends each.

- **H-1 — Story 0064's hand-off item 7 is amended.** It recommended that 0065's listener catch and report its own
  failures. **The owner's instruction supersedes it** (**D-0**): the listener does not swallow, so the sweep can detect the
  failure and 0064b can report it. 0064's hand-off should gain a superseding note (not a rewrite).
- **H-2 — Story 0065 is amended.** (a) `SendBlogPostPublishedNotification::handle()` does **not** catch: an exception from
  `NotifyBlogPostPublished` propagates to `ScheduledBlogPostPublished::dispatch()`. (b) 0065's tests pin that propagation
  rather than swallowing. (c) Its end-to-end test is extended (once both ship) to assert that a failing announcement
  produces this story's `announce` notice. (d) Its D-3 ("no queue") is unaffected. **Note the manual triggers:** the same
  `NotifyBlogPostPublished` is also called from `UpdateBlogPost`/`CreateBlogPost`; an error there surfaces to the editor's
  request as it does today — this story does not change that.
- **H-3 — Story 0057 (the bell), a follow-up.** A fourth notification `type` will exist:
  `App\Notifications\ScheduledBlogPostPublishFailed`, payload `{blog_post_id, title (nullable), stage}`. It renders through the
  permanent `default` arm until an arm is added (link to `blog-posts.edit`, a `stage`-dependent label); read the keys
  defensively and render `title` verbatim, as 0065's hand-off says.
- **H-4 — Story 0063.** Nothing to change; this story consumes `blog-posts.index` and `blog-posts.edit` (`{blogPost}`) and
  must not be started before they ship. 0063's editor gets `created_by` for free through `CreateBlogPost`.
- **H-5 — Story 0078.** The single title read in the notifier becomes `translated('title')` in the store default language;
  its migration timestamp must sort after this story's, and it rebases the shared ER/`schema-blog.md` edits if it lands second.
- **H-6 — Stories 0066/0067.** When a locale preference exists, this notification's copy should resolve in the recipient's
  locale instead of the app locale.
- **H-7 — Task-coordination files.** `ai-spec/tasks-map.md` and `ai-spec/tasks-status.json` are regenerated when this
  file is created and again when it moves stage, per
  [task-files-links-and-ordering.md](../../docs/workflow/task-files-links-and-ordering.md#regenerating-the-task-coordination-files).

## Provenance

- **Origin:** the human owner's instruction while closing story 0064 (decision 4A, extended), quoted in the
  Description.
- **Process:** [workflow.md](../../docs/workflow/phases.md#phase-1--three-amigos-debate) Phase 1, run on 2026-09-26 by
  `product-owner` as facilitator with `backend-expert`, `backend-qa` and `database-expert` (the last because a migration is
  involved). The agreed design was fixed by the facilitator's brief; this file records each part as a decision with its
  reason and the rejected alternative.
- **Upstream contracts:** [0064](done/0064-scheduled-post-auto-publish-backend.md) (the command, the action, the event,
  the Phase 5 at-most-once finding, hand-off item 7) and [0065](0065-blog-post-published-notification-backend.md) (the
  listener, D-1 recipients, D-4/D-4a the frozen-title reasoning, D-9 the event's payload).
- **Sibling shapes:** `App\Notifications\OrderCreated` / `CustomerCreated` (the notification shape, no `lang` in `data`),
  `UserInvitation` / `PendingEmailVerification` (the mail shape and the queueing rule), `media.uploaded_by` and
  `refunds.refunded_by` (the creator column).
- **Gherkin conventions:** [gherkin-guidelines.md](../../docs/testing/frontend/gherkin-guidelines.md) rules 1 and 3 and the
  *non-person actor* section (0064's D-14).

**`backend-qa` supplied the three corrections that changed this document** — resolve recipients **before** claiming the dedup
key and release it on a failed send (**D-3**), gate the creator on `blog.edit` (**D-8**), and escape the title in the email
(**D-9**) — plus the whole test design, including the unreachable-creator dataset, the privacy marker, the hostile-title case
and the observation that the "default to publish" branch is nearly unreachable (**OQ-2**). **`database-expert` supplied** the
FK shape and its soft-delete caveat, the no-extra-index and no-backfill rulings, the five-index correction, the 0078 position
analysis, and the rejection of every dedup schema (**D-6**, **D-7**). **`backend-expert` supplied** the single capture site,
the ungated-collaborator shape, the queue/mail structure and the primitives-only notification.

**Three facilitator findings changed this document rather than merely supporting it**, each checked against the code:

1. **`ShouldQueue` defers the database channel too** (**V-6**, **D-5**): the agreed design reads as "the bell is immediate and
   only the mail waits", and that is not what the framework does; it is surfaced as a consequence and as **OQ-10(c)**.
2. **`blog_posts` will have five indexes, not four** (**V-15**): a docs claim (*"exactly four"*) becomes wrong the moment the FK
   lands; it is in the docs-keeper list and in the schema test.
3. **A `Scheduled`-but-not-due or `Draft` post on re-read must report nothing** (**D-2**): the brief's two-way split (Scheduled
   means publish, Published means announce) would have mailed *"could not be published at its scheduled time"* about a post an
   editor had just rescheduled or drafted.

**Not yet run:** Phase 2 (`code-reviewer` INVEST validation). Items that deserve an explicit look there: **OQ-1** (block on
0063), **OQ-10** (the worker dependency, and whether option (c) is worth it), **OQ-12** (`edit` without `view`), the **D-2**
re-read placement (in the notifier, not the command), and the **timestamp** of the migration against 0078's.

**Stage:** `new`, and **blocked** — see the banner under [Description](#description). It moves to
`ai-spec/tasks/in-progress/` at the start of Phase 3 and to `ai-spec/tasks/done/` at Phase 7; both moves change this file's
directory depth, so every relative link above must be re-resolved in **both directions** on each move, per
[task-files-links-and-ordering.md](../../docs/workflow/task-files-links-and-ordering.md#link-integrity-check-on-every-stage-move).
