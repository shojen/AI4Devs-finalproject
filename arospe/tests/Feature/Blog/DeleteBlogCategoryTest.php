<?php

use App\Actions\Blog\CreateBlogCategory;
use App\Actions\Blog\DeleteBlogCategory;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\AssertionFailedError;

// Story 0058, Phase 3 (TDD "red" step), extended by story 0061 (D-18). D-13: DeleteBlogCategory
// authorizes itself first, so every test runs actingAs() an actor holding blog.delete (and
// blog.create for the reuse test).
//
// 0058's own cases above the "Story 0061" marker stay green, unmodified. Every dataset row below
// seeds decoy posts in a DIFFERENT category: without them BlogPost::count() and
// $category->posts()->count() are indistinguishable and a guard that counts the wrong thing passes.
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

// The action takes an already-resolved model, so a malformed or unknown id cannot reach it (that is
// route binding's job in the UI story). What CAN reach it is an instance whose row has since gone.
test('deleting a category whose row is already gone fails cleanly instead of reporting success', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    app(DeleteBlogCategory::class)($category);

    expect(fn () => app(DeleteBlogCategory::class)($category))->toThrow(ModelNotFoundException::class);
});

test('deleting one category leaves the others untouched', function () {
    $doomed = BlogCategory::factory()->create(['name' => 'Guías']);
    $kept = BlogCategory::factory()->create(['name' => 'Novedades']);

    app(DeleteBlogCategory::class)($doomed);

    $this->assertDatabaseHas('blog_categories', ['id' => $kept->id, 'name' => 'Novedades']);
});

// --- Story 0061, D-18: the hard block while any post still uses the category ---

/**
 * Runs the action expecting the hard block and hands the refusal back, so a test can read its
 * message and its error bag.
 */
function blogCategoryDeleteRefusal(BlogCategory $category): ValidationException
{
    try {
        app(DeleteBlogCategory::class)($category);
    } catch (ValidationException $e) {
        return $e;
    }

    throw new AssertionFailedError('Deleting the category was expected to be refused with a ValidationException.');
}

test('deleting a category with posts throws and the row still exists afterwards', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->count(5)->create(['blog_category_id' => $category->id]);
    BlogPost::factory()->count(2)->create();

    expect(fn () => app(DeleteBlogCategory::class)($category))->toThrow(ValidationException::class);

    $this->assertDatabaseHas('blog_categories', ['id' => $category->id, 'name' => 'Guías']);
    expect(BlogPost::query()->forCategory($category->id)->count())->toBe(5);
});

// Literal expected strings, never a second trans_choice() call: re-invoking it with the same
// arguments would be tautological. Three rows so a hardcoded "1 or 2" cannot pass.
test('the block message states the real count, singular and plural, keyed on blogCategoryId', function (int $count, string $message) {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->count($count)->create(['blog_category_id' => $category->id]);
    BlogPost::factory()->count(3)->create();

    $refusal = blogCategoryDeleteRefusal($category);

    expect($refusal->errors())->toBe(['blogCategoryId' => [$message]]);
    $this->assertDatabaseHas('blog_categories', ['id' => $category->id]);
})->with([
    'one post' => [1, 'This category is used by 1 post — reassign it before deleting.'],
    'two posts' => [2, 'This category is used by 2 posts — reassign them before deleting.'],
    'twelve posts' => [12, 'This category is used by 12 posts — reassign them before deleting.'],
]);

// The likeliest implementation bug: "in use" reads like "publicly visible", so a stray
// where('status', Published) on the count would let a category be deleted out from under drafts.
test('draft posts count towards the block', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->draft()->count(3)->create(['blog_category_id' => $category->id]);
    BlogPost::factory()->published()->count(2)->create();

    $refusal = blogCategoryDeleteRefusal($category);

    expect($refusal->errors()['blogCategoryId'][0])->toBe('This category is used by 3 posts — reassign them before deleting.');
    $this->assertDatabaseHas('blog_categories', ['id' => $category->id]);
});

test('scheduled posts count towards the block', function () {
    $category = BlogCategory::factory()->create();
    BlogPost::factory()->scheduled()->create(['blog_category_id' => $category->id]);

    expect(blogCategoryDeleteRefusal($category)->errors()['blogCategoryId'][0])
        ->toBe('This category is used by 1 post — reassign it before deleting.');
});

// D-7d: a trashed post still holds a live blog_category_id, so the restrictOnDelete() FK refuses
// whether or not it is trashed. An unscoped count would read 0, pass the guard, and then report
// "used by 0 posts". Assert the DIGIT so a guard that blocks for the right reason with the wrong
// number still fails.
test('a soft-deleted post still blocks, and the count includes it', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $post = BlogPost::factory()->create(['blog_category_id' => $category->id]);
    $post->delete();
    BlogPost::factory()->count(2)->create();

    $refusal = blogCategoryDeleteRefusal($category);

    expect($refusal->errors()['blogCategoryId'][0])->toBe('This category is used by 1 post — reassign it before deleting.');
    $this->assertDatabaseHas('blog_categories', ['id' => $category->id]);
});

test('deleting an unused category still succeeds, even with a trashed post in a different category', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->create()->delete();
    BlogPost::factory()->create();

    expect(app(DeleteBlogCategory::class)($category))->toBeTrue();

    $this->assertDatabaseMissing('blog_categories', ['id' => $category->id]);
});

test('reassigning the last post to another category frees the original for deletion', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $other = BlogCategory::factory()->create(['name' => 'Novedades']);
    $post = BlogPost::factory()->create(['blog_category_id' => $category->id]);

    expect(fn () => app(DeleteBlogCategory::class)($category))->toThrow(ValidationException::class);

    $post->update(['blog_category_id' => $other->id]);

    expect(app(DeleteBlogCategory::class)($category))->toBeTrue();
    $this->assertDatabaseMissing('blog_categories', ['id' => $category->id]);
    $this->assertDatabaseHas('blog_categories', ['id' => $other->id]);
});

test('a category whose row is already gone still fails as not found, not swallowed by the guard', function () {
    $category = BlogCategory::factory()->create();
    app(DeleteBlogCategory::class)($category);

    expect(fn () => app(DeleteBlogCategory::class)($category))->toThrow(ModelNotFoundException::class);
});

// No confirm-and-proceed path, proven three ways.
test('the action takes exactly one BlogCategory parameter and no force flag', function () {
    $parameters = (new ReflectionMethod(DeleteBlogCategory::class, '__invoke'))->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getType()?->getName())->toBe(BlogCategory::class);
});

test('calling the delete twice in succession is refused both times', function () {
    $category = BlogCategory::factory()->create();
    BlogPost::factory()->count(2)->create(['blog_category_id' => $category->id]);

    expect(blogCategoryDeleteRefusal($category)->errors())->toHaveKey('blogCategoryId')
        ->and(blogCategoryDeleteRefusal($category)->errors())->toHaveKey('blogCategoryId');
    $this->assertDatabaseHas('blog_categories', ['id' => $category->id]);
});

// The strongest of the three: it is what distinguishes a data-integrity rule from an authorization
// check. No privilege level can force it.
test('a Super Admin is refused exactly like any other administrator', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->count(5)->create(['blog_category_id' => $category->id]);
    BlogPost::factory()->count(2)->create();

    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');
    $this->actingAs($superAdmin);

    $refusal = blogCategoryDeleteRefusal($category);

    expect($refusal->errors())->toBe(['blogCategoryId' => ['This category is used by 5 posts — reassign them before deleting.']]);
    $this->assertDatabaseHas('blog_categories', ['id' => $category->id]);
});

// The race: a post is assigned between the count and the DELETE. The outcome must be a clean
// ValidationException, never a raw QueryException, and the category must survive -- which holds
// only because the FK is restrictOnDelete() (D-2). The collision is driven through the REAL
// constraint, never a mocked exception.
test('a post assigned between the count and the delete is refused cleanly by the FK backstop', function () {
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    $armed = true;

    BlogCategory::deleting(function (BlogCategory $deleting) use (&$armed): void {
        if ($armed) {
            $armed = false;
            BlogPost::factory()->create(['blog_category_id' => $deleting->id]);
        }
    });

    $refusal = blogCategoryDeleteRefusal($category);

    expect($refusal->errors())->toBe(['blogCategoryId' => ['This category is used by 1 post — reassign it before deleting.']]);
    $this->assertDatabaseHas('blog_categories', ['id' => $category->id]);
});

test('the refusal is logged with target_type blog_category and the reason category_still_in_use', function () {
    Log::spy();

    $actor = $this->actor;
    $category = BlogCategory::factory()->create(['name' => 'Guías']);
    BlogPost::factory()->create(['blog_category_id' => $category->id]);

    blogCategoryDeleteRefusal($category);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Privileged action refused'
            && $context['actor_id'] === $actor->id
            && $context['ability'] === 'category_still_in_use'
            && $context['target_type'] === 'blog_category'
            && $context['target_id'] === $category->id)
        ->once();
});

test('an unblocked delete writes no refusal warning', function () {
    Log::spy();

    app(DeleteBlogCategory::class)(BlogCategory::factory()->create());

    Log::shouldNotHaveReceived('warning');
});
