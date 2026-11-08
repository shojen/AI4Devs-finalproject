<?php

use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Story 0061. Mirrors tests/Feature/Models/UserTest.php and BlogTagTest.php.

test('a factory-created blog post receives a uuidv7 string primary key', function () {
    $post = BlogPost::factory()->create();

    expect($post->id)->toBeString()
        ->and(Str::isUuid($post->id, 7))->toBeTrue();
});

test('two blog posts created in immediate succession sort lexicographically in creation order', function () {
    $first = BlogPost::factory()->create();
    $second = BlogPost::factory()->create();

    expect(strcmp($first->id, $second->id))->toBeLessThan(0);
});

test('blog_category_id and status are mass-assignable', function () {
    $category = BlogCategory::factory()->create();

    $post = BlogPost::create([
        'blog_category_id' => $category->id,
        'status' => BlogPostStatus::Published,
    ]);

    $fresh = $post->fresh();

    expect($fresh->blog_category_id)->toBe($category->id)
        ->and($fresh->status)->toBe(BlogPostStatus::Published);
});

test('published_at is not mass-assignable and a forged slug never reaches the parent, so forged values are dropped', function () {
    $category = BlogCategory::factory()->create();

    $post = BlogPost::create([
        'blog_category_id' => $category->id,
        'slug' => 'hijacked',
        'published_at' => now()->subYear(),
    ]);

    $fresh = $post->fresh();

    expect($fresh->published_at)->toBeNull()
        ->and($fresh->getAttributes())->not->toHaveKey('slug');
    $this->assertDatabaseMissing('blog_post_translations', ['slug' => 'hijacked']);
});

test('blog_category_id is NOT NULL at the database level, not merely at the validation layer', function () {
    expect(fn () => DB::table('blog_posts')->insert([
        'id' => (string) Str::uuid7(),
        'status' => 'draft',
    ]))->toThrow(QueryException::class);
});

test('category() returns the post category and tags() a belongs-to-many through blog_post_tag', function () {
    $category = BlogCategory::factory()->create();
    $post = BlogPost::factory()->for($category, 'category')->create();
    $tags = BlogTag::factory()->count(2)->create();
    $post->tags()->attach($tags->modelKeys());

    expect($post->category())->toBeInstanceOf(BelongsTo::class)
        ->and($post->category->is($category))->toBeTrue()
        ->and($post->tags())->toBeInstanceOf(BelongsToMany::class)
        ->and($post->tags->modelKeys())->toEqualCanonicalizing($tags->modelKeys());

    $this->assertDatabaseCount('blog_post_tag', 2);
});

test('scopeForCategory returns only posts in that category, with a decoy in another', function () {
    $category = BlogCategory::factory()->create();
    $mine = BlogPost::factory()->for($category, 'category')->create();
    BlogPost::factory()->create();

    expect(BlogPost::query()->forCategory($category->id)->pluck('id')->all())->toBe([$mine->id]);
});

test('scopeForTag returns only posts carrying that tag, with a decoy carrying another', function () {
    $tag = BlogTag::factory()->create();
    $mine = BlogPost::factory()->create();
    $mine->tags()->attach($tag->id);
    $decoy = BlogPost::factory()->create();
    $decoy->tags()->attach(BlogTag::factory()->create()->id);
    BlogPost::factory()->create();

    expect(BlogPost::query()->forTag($tag->id)->pluck('id')->all())->toBe([$mine->id]);
});

test('the model soft-deletes: the row survives with deleted_at set and leaves default queries', function () {
    $post = BlogPost::factory()->create();

    $post->delete();

    $this->assertSoftDeleted('blog_posts', ['id' => $post->id]);
    expect(BlogPost::query()->whereKey($post->id)->exists())->toBeFalse()
        ->and(BlogPost::withTrashed()->whereKey($post->id)->exists())->toBeTrue();
});

test('the model overrides no delete(), and a trashed post keeps its slug byte-identical', function () {
    $post = BlogPost::factory()->titled('Botas de invierno')->create();
    $slugBefore = $post->fresh()->translations->first()->slug;

    $post->delete();

    expect((new ReflectionMethod(BlogPost::class, 'delete'))->getDeclaringClass()->getName())->not->toBe(BlogPost::class)
        ->and(BlogPost::withTrashed()->findOrFail($post->id)->translations->first()->slug)->toBe($slugBefore);
});

test('the status column defaults to draft and casts to the BlogPostStatus enum', function () {
    $id = (string) Str::uuid7();
    DB::table('blog_posts')->insert([
        'id' => $id,
        'blog_category_id' => BlogCategory::factory()->create()->id,
    ]);

    expect(BlogPost::query()->findOrFail($id)->status)->toBe(BlogPostStatus::Draft);
});
