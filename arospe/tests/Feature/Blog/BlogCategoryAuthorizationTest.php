<?php

use App\Actions\Blog\CreateBlogCategory;
use App\Actions\Blog\DeleteBlogCategory;
use App\Actions\Blog\RenameBlogCategory;
use App\Models\BlogCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;

// Story 0058, D-13: the actions authorize THEMSELVES as their first statement, so a non-dashboard
// caller (an Artisan command, a queued job, a future second component) inherits the refusal.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * An actor holding every blog.* permission EXCEPT $withheld.
 */
function blogCategoryActorWithout(string $withheld): User
{
    $actor = User::factory()->create();
    $actor->givePermissionTo(array_values(array_diff(
        ['blog.view', 'blog.create', 'blog.edit', 'blog.delete'],
        [$withheld],
    )));

    return $actor;
}

test('CreateBlogCategory refuses an actor without blog.create and writes nothing', function () {
    $this->actingAs(blogCategoryActorWithout('blog.create'));

    expect(fn () => app(CreateBlogCategory::class)('Guías'))->toThrow(AuthorizationException::class);
    expect(BlogCategory::count())->toBe(0);
});

test('RenameBlogCategory refuses an actor without blog.edit and writes nothing', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoryActorWithout('blog.edit'));

    expect(fn () => app(RenameBlogCategory::class)($category, 'Tutoriales'))->toThrow(AuthorizationException::class);
    expect($category->fresh()->name)->toBe('Guías');
});

test('DeleteBlogCategory refuses an actor without blog.delete and writes nothing', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $this->actingAs(blogCategoryActorWithout('blog.delete'));

    expect(fn () => app(DeleteBlogCategory::class)($category))->toThrow(AuthorizationException::class);
    $this->assertDatabaseHas('blog_categories', ['id' => $category->id]);
});

test('authorization runs before validation, so an unauthorized blank name is an AuthorizationException', function () {
    $this->actingAs(blogCategoryActorWithout('blog.create'));

    expect(fn () => app(CreateBlogCategory::class)(''))->toThrow(AuthorizationException::class);
});

test('a refused create is logged with target_type blog_category', function () {
    Log::spy();
    $actor = blogCategoryActorWithout('blog.create');
    $this->actingAs($actor);

    try {
        app(CreateBlogCategory::class)('Guías');
    } catch (AuthorizationException) {
        //
    }

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && $context['actor_id'] === $actor->id
            && $context['ability'] === 'create'
            && $context['target_type'] === 'blog_category')
        ->once();
});

test('a refused rename and a refused delete are logged with the target category id', function () {
    Log::spy();
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    $editor = blogCategoryActorWithout('blog.edit');
    $this->actingAs($editor);

    try {
        app(RenameBlogCategory::class)($category, 'Tutoriales');
    } catch (AuthorizationException) {
        //
    }

    $remover = blogCategoryActorWithout('blog.delete');
    $this->actingAs($remover);

    try {
        app(DeleteBlogCategory::class)($category);
    } catch (AuthorizationException) {
        //
    }

    foreach ([[$editor, 'update'], [$remover, 'delete']] as [$actor, $ability]) {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
                && $context['actor_id'] === $actor->id
                && $context['ability'] === $ability
                && $context['target_type'] === 'blog_category'
                && $context['target_id'] === $category->id)
            ->once();
    }
});
