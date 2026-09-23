<?php

use App\Actions\Blog\CreateBlogCategory;
use App\Actions\Blog\DeleteBlogCategory;
use App\Models\BlogCategory;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

// Story 0058, Phase 3 (TDD "red" step). D-13: DeleteBlogCategory authorizes itself first, so every
// test runs actingAs() an actor holding blog.delete (and blog.create for the reuse test).
//
// Deliberately NO in-use / hard-block test here: blog_posts does not exist yet, so "assigned to N
// posts" would have to be faked -- testing behaviour the action does not implement and giving false
// confidence that story 0061's guard is already covered.
beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->actor = User::factory()->create();
    $this->actor->givePermissionTo(['blog.delete', 'blog.create']);
    $this->actingAs($this->actor);
});

test('deleting a category removes the row outright', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    $deleted = app(DeleteBlogCategory::class)($category);

    expect($deleted)->toBeTrue();
    $this->assertDatabaseMissing('blog_categories', ['id' => $category->id]);
});

test('the freed name can immediately be reused by a new category', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);

    app(DeleteBlogCategory::class)($category);

    $reused = app(CreateBlogCategory::class)('Guías');

    expect($reused->fresh()->name)->toBe('Guías')
        ->and(BlogCategory::count())->toBe(1);
});

test('deleting one category leaves the others untouched', function () {
    $doomed = BlogCategory::factory()->create(['name' => 'Guías']);
    $kept = BlogCategory::factory()->create(['name' => 'Novedades']);

    app(DeleteBlogCategory::class)($doomed);

    $this->assertDatabaseHas('blog_categories', ['id' => $kept->id, 'name' => 'Novedades']);
});
