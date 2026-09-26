<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

// Story 0064, D-2 / D-3 / D-8 / R-7: the schedule entry itself.
//
// Without this, `routes/console.php` never loading -- or the entry being deleted in a refactor -- leaves
// a scheduler that silently does nothing: the command still appears in `php artisan list` because it is
// auto-discovered from app/Console/Commands independently of routes/console.php. Every property read
// here is public on the framework's Event (verified against laravel/framework v13), so each assertion
// can genuinely go red, and the entry was proven able to fail by commenting it out (see the task file).
//
// A configuration-shape check, explicitly NOT a concurrency test: withoutOverlapping()'s mutex is only
// consulted by `schedule:run`'s own dispatch, and phpunit.xml pins CACHE_STORE=array, so real overlap
// is untestable here rather than covered by a test that cannot fail.

/**
 * @return array<int, Event>
 */
function publishScheduledPostsEntries(): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'blog:publish-scheduled-posts'))
        ->values()
        ->all();
}

test('the sweep is registered on the schedule exactly once', function () {
    expect(publishScheduledPostsEntries())->toHaveCount(1);
});

// The literal string, never re-derived from the helper that set it (D-3).
test('it runs every minute', function () {
    expect(publishScheduledPostsEntries()[0]->getExpression())->toBe('* * * * *');
});

// D-8: an explicit five-minute expiry, never the 1,440-minute default that would let one crashed run
// wedge every subsequent tick for a day.
test('it is protected against overlapping itself, with a five-minute mutex expiry', function () {
    $entry = publishScheduledPostsEntries()[0];

    expect($entry->withoutOverlapping)->toBeTrue()
        ->and($entry->expiresAt)->toBe(5);
});
