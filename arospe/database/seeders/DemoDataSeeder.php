<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogTag;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Fills a local or demo environment with realistic-looking data (story 0081): customers, orders,
 * products, product categories, blog posts, blog categories and blog tags, exactly 10 of each
 * except orders (driven off the customer pool so no customer is left order-less). Every step
 * builds only on pools already created by an earlier step, so nothing ever references a row that
 * does not exist yet.
 *
 * Deliberately does NOT use `WithoutModelEvents` (unlike `DatabaseSeeder`): `BlogPost::booted()`
 * has a `saving()` listener that derives `slug` from `title`, and suppressing model events would
 * silently create posts with empty/invalid slugs. The environment guard lives in the console
 * command (`App\Console\Commands\GenerateDemoData`), not here -- this seeder has none of its own.
 */
class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $creators = $this->resolveCreatorPool();

        $categories = ProductCategory::factory()->count(10)->create();

        $products = $categories->map(
            fn (ProductCategory $category): Product => Product::factory()->active()->create([
                'product_category_id' => $category->id,
            ])
        );

        $customers = Customer::factory()->count(10)->create();

        $this->seedOrders($customers, $products);

        $blogCategories = BlogCategory::factory()->count(10)->create();
        $blogTags = BlogTag::factory()->count(10)->create();

        $this->seedBlogPosts($blogCategories, $blogTags, $creators);
    }

    /**
     * Resolve the pool of users allowed to author a blog post (D-5): reuse every existing `User`
     * row, or create exactly one fallback user when the database has none at all.
     *
     * @return Collection<int, User>
     */
    private function resolveCreatorPool(): Collection
    {
        $creators = User::query()->get();

        if ($creators->isEmpty()) {
            $creators = new Collection([
                User::factory()->create([
                    'password' => Str::password(32),
                ]),
            ]);
        }

        return $creators;
    }

    /**
     * Create 1-3 orders for each of the 10 seeded customers (D-4), guaranteeing no customer is
     * left order-less. Each order gets 1-4 line items drawn explicitly from the seeded product
     * pool, then has its `subtotal`/`total` recomputed from the real item totals -- mirroring
     * `OrderFactory::withItems()`'s own recompute, without that state's nested `Product::factory()`
     * fan-out.
     *
     * Story 0082: so the dashboard's Real income and cancelled-orders views have something to show,
     * the FIRST seeded order is paid (`OrderFactory::paid()`, not cancelled) and the SECOND is
     * cancelled -- deterministic, like the always-tagged first blog post, so a test cannot flake.
     * No order is ever refunded or partially refunded: a refund needs the line-level `RecordRefund`
     * data this seeder does not build.
     *
     * @param  Collection<int, Customer>  $customers
     * @param  Collection<int, Product>  $products
     */
    private function seedOrders(Collection $customers, Collection $products): void
    {
        $seededOrders = 0;

        foreach ($customers as $customer) {
            $orderCount = fake()->numberBetween(1, 3);

            for ($i = 0; $i < $orderCount; $i++) {
                $seededOrders++;

                $factory = Order::factory()->forCustomer($customer);

                if ($seededOrders === 1) {
                    $factory = $factory->paid();
                } elseif ($seededOrders === 2) {
                    $factory = $factory->state(['status' => OrderStatus::Cancelled]);
                }

                $order = $factory->create();

                $itemCount = fake()->numberBetween(1, 4);

                for ($j = 0; $j < $itemCount; $j++) {
                    $product = $products->random();

                    OrderItem::factory()->for($order)->create([
                        'product_id' => $product->id,
                    ]);
                }

                $subtotal = $order->items()->get()->sum(fn (OrderItem $item): float => (float) $item->line_total);

                $order->forceFill([
                    'subtotal' => number_format($subtotal, 2, '.', ''),
                    'total' => number_format($subtotal + (float) $order->tax_amount + (float) $order->shipping_amount, 2, '.', ''),
                ])->save();
            }
        }
    }

    /**
     * Create 10 published blog posts, one per seeded blog category (round-robin), each authored
     * by a randomly-picked user from the creator pool (D-5). The first seeded post is always
     * tagged (deterministic, so at least one tagged post is guaranteed); every other post has a
     * ~70% chance of being tagged, with 1-3 distinct tags from the shared pool (D-4).
     *
     * @param  Collection<int, BlogCategory>  $blogCategories
     * @param  Collection<int, BlogTag>  $blogTags
     * @param  Collection<int, User>  $creators
     */
    private function seedBlogPosts(Collection $blogCategories, Collection $blogTags, Collection $creators): void
    {
        foreach ($blogCategories as $index => $blogCategory) {
            $post = BlogPost::factory()
                ->published()
                ->createdBy($creators->random())
                ->create(['blog_category_id' => $blogCategory->id]);

            $isFirstPost = $index === 0;

            if ($isFirstPost || fake()->boolean(70)) {
                $post->tags()->attach($blogTags->random(fake()->numberBetween(1, 3))->pluck('id'));
            }
        }
    }
}
