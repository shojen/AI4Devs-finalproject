<?php

// Story 0064b, Phase 3 (TDD "red" step). The migration itself
// (database/migrations/2026_09_27_123801_add_created_by_to_blog_posts_table.php) already exists and
// is applied in this worktree, so the "migration shape" describe() block below may already be green
// -- that is expected, not a mistake, since 0064b's own status note says the schema half of D-7 is
// already done. Everything that reads App\Models\BlogPost::creator(), stamps created_by from
// CreateBlogPost, or uses BlogPostFactory::createdBy() is expected to fail red until backend-expert
// implements the rest of D-7 (the model relation, the one-line CreateBlogPost stamp, and the factory
// state).
//
// No actingAs() anywhere except the one test that needs an actor to prove CreateBlogPost stamps it
// (docs/testing/backend/scheduled-commands.md's own discipline, extended here to a non-sweep action
// because D-7 states the actor is read ONLY by CreateBlogPost, never elsewhere).

use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\UpdateBlogPost;
use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\Blog\ScheduledPosts;

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

/**
 * @param  array<int, string>  $permissions
 */
function creatorTestEditor(array $permissions = ['blog.view', 'blog.create', 'blog.edit']): User
{
    $editor = User::factory()->create();
    $editor->givePermissionTo($permissions);

    return $editor;
}

describe('migration shape (D-7)', function () {
    // Risk: a constrained() that infers the wrong table, or a restrictOnDelete() that blocks
    // deleting a user. Mutation: restrictOnDelete(); ->index('created_by').
    test('created_by is a nullable CHAR(36) FK to users.id with ON DELETE SET NULL', function () {
        $columns = collect(Schema::getColumns('blog_posts'))->keyBy('name');

        expect($columns->has('created_by'))->toBeTrue()
            ->and($columns['created_by']['nullable'])->toBeTrue()
            ->and($columns['created_by']['type'])->toBe('char(36)');

        $foreignKeys = collect(Schema::getForeignKeys('blog_posts'))->keyBy(fn (array $fk): string => $fk['columns'][0]);

        expect($foreignKeys->has('created_by'))->toBeTrue()
            ->and($foreignKeys['created_by']['foreign_table'])->toBe('users')
            ->and($foreignKeys['created_by']['foreign_columns'])->toBe(['id'])
            ->and($foreignKeys['created_by']['on_delete'])->toBe('set null');
    });

    // V-15 / the facilitator's own correction: blog_posts moves from four indexes to five the moment
    // this FK lands. Mutation: a hand-written $table->index('created_by') added on top of
    // constrained()'s own -- this pins the exact, sorted index-name list, so a redundant sixth index
    // fails it just as loudly as a missing one.
    test('blog_posts has exactly five indexes, with no hand-written index on created_by', function () {
        $indexNames = collect(Schema::getIndexes('blog_posts'))->pluck('name')->sort()->values()->all();

        expect($indexNames)->toBe([
            'blog_posts_blog_category_id_foreign',
            'blog_posts_created_by_foreign',
            'blog_posts_deleted_at_status_published_at_index',
            'blog_posts_slug_unique',
            'primary',
        ]);
    });

    // Rollback: read the migration's own source rather than actually running migrate:rollback
    // against the shared RefreshDatabase-backed suite database. MySQL DDL is not transactional, so
    // rolling back and re-migrating created_by mid-suite would leave every LATER test in this run
    // without the column regardless of RefreshDatabase's per-test transaction wrapper -- the DoD's
    // own "run migrate/migrate:rollback against a scratch database" item is a separate, manual
    // Phase-3-closing verification (see the task file's Definition of Done), not this Pest assertion.
    test('the migration file defines a down() that drops the created_by foreign key and column', function () {
        $files = glob(database_path('migrations/*_add_created_by_to_blog_posts_table.php'));

        expect($files)->not->toBeEmpty();

        $source = (string) file_get_contents((string) $files[0]);

        expect($source)->toContain("dropForeign(['created_by'])")
            ->and($source)->toContain("dropColumn('created_by')");
    });
});

describe('the creator() relation (D-7)', function () {
    // Eloquent silently returns null for an undefined property whether or not a `creator()` method
    // exists at all (this app enables no preventAccessingMissingAttributes()), so a bare
    // "->creator is null" assertion could pass vacuously with no relation declared. Calling
    // creator() AS A METHOD forces a real "call to undefined method" error until it exists, and the
    // live-creator case proves the relation genuinely resolves to a row rather than always null.
    test('creator() is declared as a BelongsTo and resolves the post\'s creator when one is recorded', function () {
        $creator = User::factory()->create();
        $post = BlogPost::factory()->create(['created_by' => $creator->id]);

        expect($post->creator())->toBeInstanceOf(BelongsTo::class)
            ->and($post->fresh()->creator)->not->toBeNull()
            ->and($post->fresh()->creator->id)->toBe($creator->id);
    });

    // Risk: the clause is documentation with no proof. Mutation: restrictOnDelete() (throws 23000).
    // A raw statement, never User::delete() -- User uses SoftDeletes, and a soft delete is an
    // UPDATE, which never fires an ON DELETE clause at all (exactly the media.uploaded_by caveat).
    test('hard-deleting the creator nulls created_by and the post survives', function () {
        $creator = User::factory()->create();
        $post = BlogPost::factory()->create(['created_by' => $creator->id]);

        DB::table('users')->where('id', $creator->id)->delete();

        expect(DB::table('blog_posts')->where('id', $post->id)->value('created_by'))->toBeNull();
        $this->assertDatabaseHas('blog_posts', ['id' => $post->id]);
    });

    // The media.uploaded_by caveat, pinned here so nobody "fixes" App\Models\BlogPost::creator()
    // into a withTrashed() relation: a SOFT-deleted creator must resolve to null exactly like a
    // hard-deleted (nulled-column) one, and both fall back to the blog.edit holders (D-8) with no
    // branch on which of the two happened. Meaningful only alongside the live-creator test above,
    // which proves the relation is not simply always null.
    test('a soft-deleted creator resolves creator() to null, while the raw column stays populated', function () {
        $creator = User::factory()->create();
        $post = BlogPost::factory()->create(['created_by' => $creator->id]);

        $creator->delete();

        expect($post->fresh()->creator)->toBeNull()
            ->and(DB::table('blog_posts')->where('id', $post->id)->value('created_by'))->toBe($creator->id);
    });
});

describe('CreateBlogPost stamps the actor (D-7)', function () {
    // The one test in this file that DOES actingAs() -- CreateBlogPost is the single writer of
    // created_by, and it must read the actor, not a parameter (there is none to pass). Mutation:
    // drop the 'created_by' line; hard-code null.
    test('a newly created post records the authenticated actor as its creator', function () {
        $editor = creatorTestEditor();
        $this->actingAs($editor);

        $post = app(CreateBlogPost::class)(
            'Botas de invierno',
            '<p>Cuerpo</p>',
            BlogCategory::factory()->create()->id,
            BlogPostStatus::Draft->value,
            null,
            [],
        );

        expect($post->created_by)->toBe($editor->id)
            ->and($post->fresh()->created_by)->toBe($editor->id);
    });

    // created_by is deliberately absent from #[Fillable] (D-7): a forged value in a create() payload
    // must be dropped, exactly like the sibling slug/published_at test in
    // tests/Feature/Models/BlogPostTest.php ("slug and published_at are not mass-assignable, so
    // forged values are replaced or dropped") -- this app enables no
    // preventSilentlyDiscardingAttributes(), so the observable behaviour is a silent drop to null,
    // never a MassAssignmentException. Mutation: add created_by to #[Fillable].
    test('created_by is not mass-assignable through a plain create() payload', function () {
        $category = BlogCategory::factory()->create();
        $other = User::factory()->create();

        $post = BlogPost::create([
            'title' => 'Sandalias de verano',
            'blog_category_id' => $category->id,
            'status' => BlogPostStatus::Draft,
            'created_by' => $other->id,
        ]);

        expect($post->fresh()->created_by)->toBeNull();
    });

    test('created_by is not settable through a plain fill(), because it is not fillable', function () {
        $other = User::factory()->create();

        $post = new BlogPost;
        $post->fill(['created_by' => $other->id]);

        expect($post->getAttribute('created_by'))->toBeNull();
    });
});

describe('created_by is immutable once set (D-7)', function () {
    // Risk: "created by" silently becomes "last edited by". Mutation: a
    // forceFill(['created_by' => Auth::id()]) in UpdateBlogPost.
    test('UpdateBlogPost by a different editor does not change created_by', function () {
        $creator = creatorTestEditor();
        $this->actingAs($creator);
        $post = app(CreateBlogPost::class)(
            'Botas de invierno',
            '<p>Cuerpo</p>',
            BlogCategory::factory()->create()->id,
            BlogPostStatus::Draft->value,
            null,
            [],
        );

        $otherEditor = creatorTestEditor();
        $this->actingAs($otherEditor);

        app(UpdateBlogPost::class)(
            $post,
            'Botas de invierno (editado)',
            '<p>Cuerpo editado</p>',
            $post->blog_category_id,
            BlogPostStatus::Draft->value,
            null,
            [],
        );

        expect($post->fresh()->created_by)->toBe($creator->id)
            ->and($post->fresh()->created_by)->not->toBe($otherEditor->id);
    });

    // A raw, non-fillable mass-assignment attempt through the plain Eloquent update() path -- the
    // same guard exercised at create time, exercised again at update time. save() writes the whole
    // dirty set (docs/security/model-instance-trust.md), so this proves the OMISSION from
    // #[Fillable] is what protects the column, not something UpdateBlogPost itself does.
    test('a raw mass-assignment update() never changes created_by, because it is not fillable', function () {
        $creator = User::factory()->create();
        $other = User::factory()->create();
        $post = BlogPost::factory()->create(['created_by' => $creator->id]);

        $post->update(['title' => 'Nuevo título', 'created_by' => $other->id]);

        expect($post->fresh()->created_by)->toBe($creator->id);
    });
});

describe('factory (D-7)', function () {
    test('the factory default leaves created_by null', function () {
        $post = BlogPost::factory()->create();

        expect($post->fresh()->created_by)->toBeNull();
    });

    test('the createdBy() state sets created_by to the given user', function () {
        $user = User::factory()->create();

        $post = BlogPost::factory()->createdBy($user)->create();

        expect($post->fresh()->created_by)->toBe($user->id);
    });
});

describe('a legacy row with no recorded creator (D-7, D-8 prerequisite)', function () {
    // Risk: a ->creator->id dereference somewhere in the sweep path. A NULL created_by must be a
    // first-class state the sweep and the notifier both handle, not a crash.
    test('a scheduled post with no recorded creator still publishes normally through the sweep', function () {
        Carbon::setTestNow('2026-09-26 12:00:00');

        $post = ScheduledPosts::scheduled();
        expect($post->created_by)->toBeNull();

        $this->artisan('blog:publish-scheduled-posts')->assertExitCode(0);

        expect(BlogPost::query()->find($post->id)->status)->toBe(BlogPostStatus::Published);

        Carbon::setTestNow();
    });
});
