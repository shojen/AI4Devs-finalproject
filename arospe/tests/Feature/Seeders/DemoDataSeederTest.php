<?php

use App\Enums\BlogPostStatus;
use App\Enums\ProductStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Support\Facades\DB;

// Story 0081: DemoDataSeeder fills a local/demo environment with realistic, fully-wired data --
// customers, orders, products, product categories, blog posts, blog categories and blog tags --
// in an explicit dependency order so nothing ever references a row that does not exist yet.
//
// Main risk (QA, per the story): several factories default their parent relation to a nested
// `Model::factory()` call (OrderFactory's customer_id, ProductFactory's product_category_id,
// BlogPostFactory's blog_category_id), so a naive loop would silently fan out extra rows. These
// tests therefore assert the pools stay at their EXACT intended size, not just "at least N rows
// exist". Faker's own output shape (email format, sentence structure) is not tested -- the
// factories already own that.

test('running the seeder creates exactly 10 of every entity, and at least 10 orders', function () {
    (new DemoDataSeeder)();

    expect(Customer::count())->toBe(10)
        ->and(ProductCategory::count())->toBe(10)
        ->and(Product::count())->toBe(10)
        ->and(BlogCategory::count())->toBe(10)
        ->and(BlogTag::count())->toBe(10)
        ->and(BlogPost::count())->toBe(10)
        ->and(Order::count())->toBeGreaterThanOrEqual(10);
});

test('every product belongs to a seeded category, and every category has exactly one product', function () {
    (new DemoDataSeeder)();

    $categoryIds = ProductCategory::query()->pluck('id');

    expect(ProductCategory::count())->toBe(10)
        ->and(Product::query()->whereNotIn('product_category_id', $categoryIds)->exists())->toBeFalse();

    ProductCategory::query()->withCount('products')->get()->each(
        fn (ProductCategory $category) => expect($category->products_count)->toBe(1)
    );
});

test('every seeded product is active', function () {
    (new DemoDataSeeder)();

    expect(Product::query()->where('status', '!=', ProductStatus::Active)->exists())->toBeFalse();
});

test('every one of the 10 seeded customers has between 1 and 3 orders', function () {
    (new DemoDataSeeder)();

    expect(Customer::count())->toBe(10)
        ->and(Customer::query()->doesntHave('orders')->exists())->toBeFalse();

    Customer::query()->withCount('orders')->get()->each(
        fn (Customer $customer) => expect($customer->orders_count)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3)
    );
});

test('every order has 1 to 4 items with a subtotal and total matching their sum', function () {
    (new DemoDataSeeder)();

    Order::query()->with('items')->get()->each(function (Order $order): void {
        expect($order->items->count())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(4);

        $itemsSum = $order->items->sum(fn ($item): float => (float) $item->line_total);

        expect((float) $order->subtotal)->toBeGreaterThan(0.0)
            ->and((float) $order->subtotal)->toEqualWithDelta($itemsSum, 0.001)
            ->and((float) $order->total)->toBeGreaterThan(0.0)
            ->and((float) $order->total)->toEqualWithDelta($itemsSum, 0.001);
    });
});

test('every order references one of the 10 seeded customers, and the customer pool stays exact', function () {
    (new DemoDataSeeder)();

    $customerIds = Customer::query()->pluck('id');

    expect(Customer::count())->toBe(10)
        ->and(Order::query()->whereNotIn('customer_id', $customerIds)->exists())->toBeFalse();
});

test('every order item references one of the 10 seeded products, and the product pool stays exact', function () {
    (new DemoDataSeeder)();

    $productIds = Product::query()->pluck('id');

    expect(Product::count())->toBe(10)
        ->and(DB::table('order_items')->whereNotIn('product_id', $productIds)->exists())->toBeFalse();
});

test('every blog post belongs to a seeded blog category and is published', function () {
    (new DemoDataSeeder)();

    $categoryIds = BlogCategory::query()->pluck('id');

    expect(BlogCategory::count())->toBe(10)
        ->and(BlogPost::query()->whereNotIn('blog_category_id', $categoryIds)->exists())->toBeFalse()
        ->and(BlogPost::query()->where('status', '!=', BlogPostStatus::Published)->exists())->toBeFalse();
});

test('every blog post is authored by a real, existing user', function () {
    (new DemoDataSeeder)();

    $userIds = User::query()->pluck('id');

    expect(BlogPost::query()->whereNull('created_by')->exists())->toBeFalse()
        ->and(BlogPost::query()->whereNotIn('created_by', $userIds)->exists())->toBeFalse();
});

test('when users already exist, the seeder reuses them and creates no additional user', function () {
    User::factory()->count(3)->create();
    $existingUserIds = User::query()->pluck('id')->all();

    (new DemoDataSeeder)();

    expect(User::count())->toBe(3)
        ->and(BlogPost::query()->whereNotIn('created_by', $existingUserIds)->exists())->toBeFalse();
});

test('when no user exists, the seeder creates exactly one fallback user and authors every post with it', function () {
    expect(User::count())->toBe(0);

    (new DemoDataSeeder)();

    expect(User::count())->toBe(1);

    $fallbackUserId = User::query()->value('id');

    expect(BlogPost::query()->where('created_by', '!=', $fallbackUserId)->exists())->toBeFalse();
});

test('running the seeder creates exactly 10 blog tags', function () {
    (new DemoDataSeeder)();

    expect(BlogTag::count())->toBe(10);
});

test('the first seeded blog post always has 1 to 3 tags attached from the shared pool', function () {
    (new DemoDataSeeder)();

    $firstPost = BlogPost::query()->orderBy('id')->first();
    $tagIds = BlogTag::query()->pluck('id');

    $attachedTagIds = DB::table('blog_post_tag')->where('blog_post_id', $firstPost->id)->pluck('blog_tag_id');

    expect($attachedTagIds->count())->toBeGreaterThanOrEqual(1)
        ->and($attachedTagIds->count())->toBeLessThanOrEqual(3)
        ->and($attachedTagIds->diff($tagIds))->toBeEmpty();
});

test('no blog post has the same tag attached twice', function () {
    (new DemoDataSeeder)();

    $pivotRows = DB::table('blog_post_tag')->get(['blog_post_id', 'blog_tag_id']);

    $distinctPairCount = $pivotRows
        ->map(fn ($row): string => $row->blog_post_id.'|'.$row->blog_tag_id)
        ->unique()
        ->count();

    expect($pivotRows)->toHaveCount($distinctPairCount);
});

// Regression test: guards against a future `WithoutModelEvents` being added to the seeder, which
// would silently bypass BlogPost::booted()'s `saving()` listener that derives `slug` from `title`.
test('every seeded blog post has a non-empty slug derived from its title', function () {
    (new DemoDataSeeder)();

    expect(BlogPost::query()->where('slug', '')->orWhereNull('slug')->exists())->toBeFalse()
        ->and(BlogPost::count())->toBe(10);
});
