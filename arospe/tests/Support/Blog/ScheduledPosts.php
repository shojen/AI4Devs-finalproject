<?php

namespace Tests\Support\Blog;

use App\Models\BlogPost;
use Illuminate\Support\Facades\DB;

/**
 * Fixtures shared by the story 0064 scheduled-sweep tests (the action, its event and the command).
 * Static methods rather than global Pest helpers, following tests/Support/Orders: a global function
 * redeclared by a second test file is a fatal error.
 */
final class ScheduledPosts
{
    /**
     * A Scheduled post whose publication time is $secondsFromNow away (negative: already due). Built by
     * the factory, which bypasses 0061's validation, so a past date on a Scheduled row -- the state a
     * real post reaches once time passes -- is seedable directly.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function scheduled(int $secondsFromNow = -60, array $overrides = []): BlogPost
    {
        return BlogPost::factory()->scheduled()->create([
            'published_at' => now()->addSeconds($secondsFromNow),
            ...$overrides,
        ]);
    }

    /**
     * The raw stored row, read past the model so no cast or accessor can hide a difference.
     *
     * @return array<string, mixed>
     */
    public static function row(BlogPost $post): array
    {
        return (array) DB::table('blog_posts')->where('id', $post->id)->first();
    }
}
