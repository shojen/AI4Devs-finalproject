<?php

use App\Actions\Blog\CreateBlogPost;
use App\Actions\Blog\UpdateBlogPost;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

// Story 0061 (D-13, D-15, D-17), Phase 3 (TDD "red" step). Tags are attached BY NAME in the same save as
// the post, through FindOrCreateBlogTag and no other path. SyncBlogPostTags is exercised through the two
// real callers, never directly, so these tests also prove the post actions actually call it.
beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);

    $this->category = BlogCategory::factory()->create(['name' => 'Guías']);

    $this->actingAs(tagAssignmentActor(['blog.create', 'blog.edit', 'blog.view']));
});

/**
 * @param  list<string>  $permissions
 */
function tagAssignmentActor(array $permissions): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions);

    return $actor;
}

/**
 * @param  list<string>  $tagNames
 */
function createTaggedPost(BlogCategory $category, array $tagNames, string $title = 'Botas de invierno'): BlogPost
{
    return app(CreateBlogPost::class)(
        title: $title,
        body: '<p>Contenido</p>',
        blogCategoryId: $category->id,
        status: 'draft',
        publishedAt: null,
        tagNames: $tagNames,
    );
}

/**
 * Re-saves $post unchanged apart from its title and tag set.
 *
 * @param  list<string>  $tagNames
 */
function retagPost(BlogPost $post, array $tagNames, ?string $title = null): BlogPost
{
    return app(UpdateBlogPost::class)(
        $post,
        title: $title ?? $post->title,
        body: $post->body,
        blogCategoryId: $post->blog_category_id,
        status: $post->status->value,
        publishedAt: null,
        tagNames: $tagNames,
    );
}

/**
 * The names of the tags currently attached to a post, read from the real pivot rather than a relation.
 *
 * @return list<string>
 */
function attachedTagNames(BlogPost $post): array
{
    return DB::table('blog_post_tag')
        ->join('blog_tags', 'blog_tags.id', '=', 'blog_post_tag.blog_tag_id')
        ->where('blog_post_tag.blog_post_id', $post->id)
        ->orderBy('blog_tags.name')
        ->pluck('blog_tags.name')
        ->all();
}

test('saving with an existing tag name attaches that row and creates no duplicate', function () {
    $existing = BlogTag::factory()->create(['name' => 'running']);

    $post = createTaggedPost($this->category, ['running']);

    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $existing->id]);
    expect(BlogTag::count())->toBe(1);
});

// The assertion that proves the save path calls FindOrCreateBlogTag and not CreateBlogTag: the wrong one
// throws a ValidationException on a case-differing name, invisible to every exact-match test.
test('a name differing only by case attaches the existing tag', function () {
    $existing = BlogTag::factory()->create(['name' => 'running']);

    $post = createTaggedPost($this->category, ['Running']);

    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $existing->id]);
    expect(BlogTag::count())->toBe(1);
});

test('a name differing only by accent attaches the existing tag', function () {
    $existing = BlogTag::factory()->create(['name' => 'Niño']);

    $post = createTaggedPost($this->category, ['nino']);

    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $existing->id]);
    expect(BlogTag::count())->toBe(1);
});

test('surrounding whitespace in a submitted name does not defeat the match', function () {
    $existing = BlogTag::factory()->create(['name' => 'running']);

    $post = createTaggedPost($this->category, ['  running  ']);

    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $existing->id]);
    expect(BlogTag::count())->toBe(1);
});

test('an unknown name creates the tag and attaches it', function () {
    expect(BlogTag::count())->toBe(0);

    $post = createTaggedPost($this->category, ['invierno']);

    $tag = BlogTag::where('name', 'invierno')->first();

    expect($tag)->not->toBeNull()
        ->and(BlogTag::count())->toBe(1);
    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $tag->id]);
});

test('three distinct names attach three pivot rows in one save', function () {
    BlogTag::factory()->create(['name' => 'running']);

    $post = createTaggedPost($this->category, ['running', 'invierno', 'trail']);

    expect(attachedTagNames($post))->toBe(['invierno', 'running', 'trail'])
        ->and(DB::table('blog_post_tag')->where('blog_post_id', $post->id)->count())->toBe(3);
});

test('the same name submitted twice, in different spellings, attaches one pivot row', function () {
    $post = createTaggedPost($this->category, ['running', 'Running']);

    expect(attachedTagNames($post))->toBe(['running'])
        ->and(BlogTag::count())->toBe(1);
});

test('a blank tag name is refused as a validation error and nothing is written', function () {
    expect(fn () => createTaggedPost($this->category, ['running', '   ']))->toThrow(ValidationException::class);

    expect(BlogPost::withTrashed()->count())->toBe(0)
        ->and(BlogTag::count())->toBe(0);
});

test('on update, a name omitted from the submitted set is detached from that post only, and the tag row survives', function () {
    $post = createTaggedPost($this->category, ['running', 'invierno']);

    // The control: a second post sharing the tag that is about to be dropped from the first.
    $sibling = createTaggedPost($this->category, ['invierno'], 'Otro artículo');

    retagPost($post, ['running']);

    expect(attachedTagNames($post))->toBe(['running'])
        ->and(attachedTagNames($sibling))->toBe(['invierno']);
    $this->assertDatabaseHas('blog_tags', ['name' => 'invierno']);
    expect(BlogTag::count())->toBe(2);
});

test('on update, adding a name keeps the tags the post already had', function () {
    $post = createTaggedPost($this->category, ['running']);

    retagPost($post, ['running', 'invierno']);

    expect(attachedTagNames($post))->toBe(['invierno', 'running']);
});

// D-13 / 0059's D-11: the per-branch ability exercised through its first real caller. Reusing an
// existing tag needs only the ability to read the catalog; minting a new one needs blog.create.
test('an actor holding blog.edit but not blog.create can attach an existing tag', function () {
    BlogTag::factory()->create(['name' => 'running']);
    $post = createTaggedPost($this->category, []);

    $this->actingAs(tagAssignmentActor(['blog.edit', 'blog.view']));

    retagPost($post, ['running']);

    expect(attachedTagNames($post))->toBe(['running']);
});

test('the same actor is refused the moment a submitted name is new, and no tag is minted', function () {
    BlogTag::factory()->create(['name' => 'running']);
    $post = createTaggedPost($this->category, []);

    $this->actingAs(tagAssignmentActor(['blog.edit', 'blog.view']));

    expect(fn () => retagPost($post, ['running', 'invierno']))->toThrow(AuthorizationException::class);

    expect(BlogTag::where('name', 'invierno')->exists())->toBeFalse();
});

// D-15: a half-saved post with two of three tags attached is a worse state than an outright failure,
// and nothing else in the suite would catch it. The refusal must roll back the post's own columns too.
test('that refusal rolls the whole save back: the post is unchanged and no pivot row from the valid names survives', function () {
    BlogTag::factory()->create(['name' => 'running']);
    BlogTag::factory()->create(['name' => 'trail']);
    $post = createTaggedPost($this->category, [], 'Título original');

    $this->actingAs(tagAssignmentActor(['blog.edit', 'blog.view']));

    expect(fn () => retagPost($post, ['running', 'trail', 'invierno'], 'Título cambiado'))
        ->toThrow(AuthorizationException::class);

    $fresh = $post->fresh();

    expect($fresh->title)->toBe('Título original')
        ->and($fresh->slug)->toBe('titulo-original')
        ->and(DB::table('blog_post_tag')->where('blog_post_id', $post->id)->count())->toBe(0)
        ->and(BlogTag::count())->toBe(2);
});

test('an actor holding blog.create but not blog.view can still attach existing tags through create', function () {
    $existing = BlogTag::factory()->create(['name' => 'running']);

    $this->actingAs(tagAssignmentActor(['blog.create']));

    $post = createTaggedPost($this->category, ['running']);

    $this->assertDatabaseHas('blog_post_tag', ['blog_post_id' => $post->id, 'blog_tag_id' => $existing->id]);
});
