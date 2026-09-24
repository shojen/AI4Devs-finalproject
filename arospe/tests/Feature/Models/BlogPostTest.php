<?php

use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

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

test('title, body, blog_category_id and status are mass-assignable', function () {
    $category = BlogCategory::factory()->create();

    $post = BlogPost::create([
        'title' => 'Botas de invierno',
        'body' => '<p>Texto</p>',
        'blog_category_id' => $category->id,
        'status' => BlogPostStatus::Draft,
    ]);

    $fresh = $post->fresh();

    expect($fresh->title)->toBe('Botas de invierno')
        ->and($fresh->body)->toBe('<p>Texto</p>')
        ->and($fresh->blog_category_id)->toBe($category->id)
        ->and($fresh->status)->toBe(BlogPostStatus::Draft);
});

test('slug and published_at are not mass-assignable, so forged values are replaced or dropped', function () {
    $category = BlogCategory::factory()->create();

    $post = BlogPost::create([
        'title' => 'Botas de invierno',
        'blog_category_id' => $category->id,
        'slug' => 'hijacked',
        'published_at' => now()->subYear(),
    ]);

    $fresh = $post->fresh();

    expect($fresh->slug)->toBe('botas-de-invierno')
        ->and($fresh->published_at)->toBeNull();
});

test('the saving hook derives slug on insert and re-derives it on a retitle', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    expect($post->fresh()->slug)->toBe('botas-de-invierno');

    $post->update(['title' => 'Botas de Invierno 2026']);

    expect($post->fresh()->slug)->toBe('botas-de-invierno-2026');
});

test('saving a blog post without touching title does not rewrite slug', function () {
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);

    // Bypass the hook to plant a slug it would never derive, then save an unrelated column.
    DB::table('blog_posts')->where('id', $post->id)->update(['slug' => 'planted']);

    $post = $post->fresh();
    $post->update(['body' => '<p>Otro texto</p>']);

    expect($post->fresh()->slug)->toBe('planted');
});

test('blog_category_id is NOT NULL at the database level, not merely at the validation layer', function () {
    expect(fn () => DB::table('blog_posts')->insert([
        'id' => (string) Str::uuid7(),
        'title' => 'Sin categoría',
        'slug' => 'sin-categoria',
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
    $post = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $slugBefore = $post->fresh()->slug;

    $post->delete();

    expect((new ReflectionMethod(BlogPost::class, 'delete'))->getDeclaringClass()->getName())->not->toBe(BlogPost::class)
        ->and(BlogPost::withTrashed()->findOrFail($post->id)->slug)->toBe($slugBefore);
});

test('a trashed post slug is still refused to a new post, while a different title saves fine', function () {
    $trashed = BlogPost::factory()->create(['title' => 'Botas de invierno']);
    $trashed->delete();

    $unique = fn (string $slug) => Validator::make(['slug' => $slug], ['slug' => Rule::unique('blog_posts', 'slug')]);

    expect($unique('botas-de-invierno')->fails())->toBeTrue()
        ->and($unique('sandalias-de-verano')->passes())->toBeTrue();

    expect(fn () => BlogPost::factory()->create(['title' => 'Botas de invierno']))->toThrow(QueryException::class);
    expect(BlogPost::factory()->create(['title' => 'Sandalias de verano'])->slug)->toBe('sandalias-de-verano');
});

test('the status column defaults to draft and casts to the BlogPostStatus enum', function () {
    $id = (string) Str::uuid7();
    DB::table('blog_posts')->insert([
        'id' => $id,
        'blog_category_id' => BlogCategory::factory()->create()->id,
        'title' => 'Sin estado',
        'slug' => 'sin-estado',
    ]);

    expect(BlogPost::query()->findOrFail($id)->status)->toBe(BlogPostStatus::Draft);
});
