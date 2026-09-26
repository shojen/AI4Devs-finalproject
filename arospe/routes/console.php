<?php

use App\Console\Commands\PublishScheduledBlogPosts;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console routes and the schedule
|--------------------------------------------------------------------------
|
| Loaded by the console kernel (bootstrap/app.php's `commands:` argument), NOT required from web.php: this
| is not an area route file and registers no HTTP route. It is where a scheduled job is declared, and
| where the next one goes.
|
| The sweep's frequency and mutex expiry are pinned by tests/Feature/Console/ScheduleRegistrationTest.php.
*/

// Every minute: "a post goes live at the time its editor chose" is the whole feature, and a coarser
// interval delays it by up to that interval (story 0064, D-3). A crashed run must not wedge the ticks
// behind it for the framework's 1,440-minute default, so the mutex expires after five (D-8).
Schedule::command(PublishScheduledBlogPosts::class)
    ->everyMinute()
    ->withoutOverlapping(5);
