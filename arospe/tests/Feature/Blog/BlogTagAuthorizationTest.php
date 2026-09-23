<?php

use App\Actions\Blog\CreateBlogTag;
use App\Actions\Blog\DeleteBlogTag;
use App\Actions\Blog\RenameBlogTag;
use App\Models\BlogTag;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;

// Story 0059, D-12: the actions authorize THEMSELVES as their first statement, so a non-dashboard
// caller (an Artisan command, a queued job, story 0061's post-save flow) inherits the refusal. Each
// action gets a deny AND a narrowness case: an actor holding every blog.* permission EXCEPT the one
// under test, so a policy that checked "any blog.*" could not pass.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * An actor holding every blog.* permission EXCEPT $withheld.
 */
function blogTagActorWithout(string $withheld): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(array_values(array_diff(
        ['blog.view', 'blog.create', 'blog.edit', 'blog.delete'],
        [$withheld],
    )));

    return $actor;
}

test('CreateBlogTag refuses an actor without blog.create and writes nothing', function () {
    $this->actingAs(blogTagActorWithout('blog.create'));

    expect(fn () => app(CreateBlogTag::class)('running'))->toThrow(AuthorizationException::class);
    expect(BlogTag::count())->toBe(0);
});

test('RenameBlogTag refuses an actor without blog.edit and writes nothing', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagActorWithout('blog.edit'));

    expect(fn () => app(RenameBlogTag::class)($tag, 'invierno'))->toThrow(AuthorizationException::class);
    expect($tag->fresh()->name)->toBe('running');
});

test('DeleteBlogTag refuses an actor without blog.delete and writes nothing', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);
    $this->actingAs(blogTagActorWithout('blog.delete'));

    expect(fn () => app(DeleteBlogTag::class)($tag))->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('blog_tags', ['id' => $tag->id]);
});

test('each action is allowed for an actor holding exactly its own permission', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    $this->actingAs(User::factory()->create()->givePermissionTo('blog.create'));
    expect(app(CreateBlogTag::class)('invierno'))->toBeInstanceOf(BlogTag::class);

    $this->actingAs(User::factory()->create()->givePermissionTo('blog.edit'));
    expect(app(RenameBlogTag::class)($tag, 'trail running')->name)->toBe('trail running');

    $this->actingAs(User::factory()->create()->givePermissionTo('blog.delete'));
    expect(app(DeleteBlogTag::class)($tag))->toBeTrue();
});

test('authorization runs before validation, so an unauthorized blank name is an AuthorizationException', function () {
    $this->actingAs(blogTagActorWithout('blog.create'));

    expect(fn () => app(CreateBlogTag::class)(''))->toThrow(AuthorizationException::class);
});

test('an unauthenticated caller is refused by every action', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    expect(fn () => app(CreateBlogTag::class)('invierno'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RenameBlogTag::class)($tag, 'invierno'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteBlogTag::class)($tag))->toThrow(AuthorizationException::class);
});
