<?php

namespace App\Livewire\Dashboard;

use App\Actions\Dashboard\GetDashboardCounters;
use App\Concerns\ChecksAbilitiesSafely;
use App\Models\BlogPost;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The dashboard home (story 0083): a hero with a greeting and the counters, and one child widget per
 * module the actor may view. Read-only and ungated at the route -- what is shown follows the actor's
 * abilities (counters inside GetDashboardCounters, widgets through `widgets()`).
 *
 * No public property: the counters and the greeting are computed per render, so nothing here is
 * client-writable or serialised into the snapshot.
 */
#[Title('Dashboard')]
class Overview extends Component
{
    use ChecksAbilitiesSafely;

    /**
     * Which widgets mount: one flag per module whose view ability the actor holds. A missing
     * permission row means "not permitted", never an error.
     *
     * @return array{blog: bool, stock: bool, orders: bool}
     */
    #[Computed]
    public function widgets(): array
    {
        return [
            'blog' => $this->allowsSafely('viewAny', BlogPost::class),
            'stock' => $this->allowsSafely('viewAny', Product::class),
            'orders' => $this->allowsSafely('viewAny', Order::class),
        ];
    }

    /**
     * The three counters; a null value means the actor may not see that module's counter.
     *
     * @return array{users: ?int, products: ?int, images: ?int}
     */
    #[Computed]
    public function counters(): array
    {
        return app(GetDashboardCounters::class)();
    }

    /**
     * The greeting, by hour in the application timezone (05:00-11:59 morning, 12:00-19:59 afternoon,
     * otherwise evening), with the user's first name.
     */
    #[Computed]
    public function greeting(): string
    {
        $hour = (int) Carbon::now(config('app.timezone'))->format('G');

        $kind = match (true) {
            $hour >= 5 && $hour < 12 => 'morning',
            $hour >= 12 && $hour < 20 => 'afternoon',
            default => 'evening',
        };

        return __('dashboard.hero.greeting_'.$kind, [
            'name' => Str::of((string) auth()->user()?->name)->before(' ')->toString(),
        ]);
    }
}
