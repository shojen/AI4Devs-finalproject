<?php

use App\Actions\Blog\NotifyBlogPostPublished;
use App\Models\BlogPost;
use App\Models\User;
use App\Notifications\BlogPostPublished;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

// Story 0065 -- the shared recipient-resolution action, exercised directly against
// app(NotifyBlogPostPublished::class) rather than through UpdateBlogPost/CreateBlogPost, mirroring
// 0043's NotifyCustomerCreatedTest.php and 0046's NotifyOrderCreatedTest.php. All three manual and
// automatic triggers converge on this one implementation (D-5), so it is written and tested once.

beforeEach(function () {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->seed(RolePermissionSeeder::class);
});

test('a user holding blog.view through a role is in the recipient set', function () {
    $role = Role::create(['name' => 'Blog Watcher', 'guard_name' => 'web']);
    $role->givePermissionTo('blog.view');

    $recipient = User::factory()->create();
    $recipient->assignRole($role);

    Notification::fake();

    $post = BlogPost::factory()->published()->create();
    app(NotifyBlogPostPublished::class)($post);

    Notification::assertSentTo($recipient, BlogPostPublished::class);
});

test('a user holding blog.view granted directly (not via a role) is in the recipient set', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('blog.view');

    Notification::fake();

    $post = BlogPost::factory()->published()->create();
    app(NotifyBlogPostPublished::class)($post);

    Notification::assertSentTo($recipient, BlogPostPublished::class);
});

// R-3: the third verbatim shape copy of one recipient query. A wrong permission string here fails
// closed and silently -- the wrong administrators are notified and nothing errors -- which is why
// every neighbouring module's .view and every wrong verb on blog itself gets its own row.
test('a user holding a wrong-shaped permission is not notified', function (string $permission) {
    $bystander = User::factory()->create();
    $bystander->givePermissionTo($permission);

    Notification::fake();

    $post = BlogPost::factory()->published()->create();
    app(NotifyBlogPostPublished::class)($post);

    Notification::assertNotSentTo($bystander, BlogPostPublished::class);
})->with([
    'blog.create (right module, wrong verb)' => ['blog.create'],
    'blog.edit (right module, wrong verb)' => ['blog.edit'],
    'blog.delete (right module, wrong verb)' => ['blog.delete'],
    'customers.view (right verb, wrong module)' => ['customers.view'],
    'orders.view (right verb, wrong module)' => ['orders.view'],
    'users.view (right verb, wrong module)' => ['users.view'],
    'sales-regions.view (right verb, wrong module)' => ['sales-regions.view'],
]);

test('a soft-deleted holder of blog.view is not notified', function () {
    $holder = User::factory()->create();
    $holder->givePermissionTo('blog.view');
    $holder->delete();

    Notification::fake();

    $post = BlogPost::factory()->published()->create();
    app(NotifyBlogPostPublished::class)($post);

    Notification::assertNotSentTo($holder, BlogPostPublished::class);
});

// D-1: the Super Admin's access is Gate::before, which grants no role_has_permissions row for this
// data query to match.
test('a Super Admin holding no explicit blog.view grant is not notified', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole('Super Admin');

    expect($superAdmin->getAllPermissions())->toHaveCount(0);

    Notification::fake();

    $post = BlogPost::factory()->published()->create();
    app(NotifyBlogPostPublished::class)($post);

    Notification::assertNotSentTo($superAdmin, BlogPostPublished::class);
});

test('with no eligible recipients, dispatch is a clean no-op', function () {
    Notification::fake();

    $post = BlogPost::factory()->published()->create();

    // No assertion failure/exception is itself the assertion.
    app(NotifyBlogPostPublished::class)($post);

    Notification::assertNothingSent();
});

test('recipients are resolved at dispatch time, never cached from a prior resolution', function () {
    Notification::fake();

    // Resolve (and thereby "warm") the action from the container BEFORE the grant exists -- pins
    // that the action itself holds no state.
    $notifyBlogPostPublished = app(NotifyBlogPostPublished::class);

    $role = Role::create(['name' => 'Late Blog Watcher', 'guard_name' => 'web']);
    $role->givePermissionTo('blog.view');

    $lateHolder = User::factory()->create();
    $lateHolder->assignRole($role);

    $post = BlogPost::factory()->published()->create();
    $notifyBlogPostPublished($post);

    Notification::assertSentTo($lateHolder, BlogPostPublished::class);
});

// D-4: asserted as a set-equality, never a series of toHaveKey() calls, so a later silent addition
// or removal is caught.
test("the payload's exact key set is blog_post_id and title", function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('blog.view');

    Notification::fake();

    $post = BlogPost::factory()->published()->create();
    app(NotifyBlogPostPublished::class)($post);

    Notification::assertSentTo($recipient, BlogPostPublished::class, function (BlogPostPublished $notification) use ($recipient): bool {
        $keys = array_keys($notification->toArray($recipient));
        sort($keys);

        return $keys === ['blog_post_id', 'title'];
    });
});

// D-4: title is a frozen literal snapshot taken at publication, not an id the viewer joins on and
// not a relation read at render time. Deliberately UN-faked: Notification::fake() would keep the
// same in-memory BlogPost object referenced by the notification, so mutating the post afterwards
// would also mutate what a later toArray() call returns -- indistinguishable from a broken
// "join at render time" implementation. Only a REAL, already-persisted `notifications.data` row,
// written at dispatch time, can prove the snapshot survives a later rename.
test('title is a frozen snapshot: renaming the post after dispatch leaves the stored notification unchanged', function () {
    $recipient = User::factory()->create();
    $recipient->givePermissionTo('blog.view');

    $post = BlogPost::factory()->published()->create(['title' => 'Guía de invierno']);

    app(NotifyBlogPostPublished::class)($post);

    $post->update(['title' => 'Botas de invierno']);

    $row = DB::table('notifications')
        ->where('notifiable_id', $recipient->id)
        ->where('type', BlogPostPublished::class)
        ->first();

    expect($row)->not->toBeNull();

    $data = json_decode($row->data, true);
    expect($data['title'])->toBe('Guía de invierno');
});
