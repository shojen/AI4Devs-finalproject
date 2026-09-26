<?php

namespace App\Console\Commands;

use App\Actions\Blog\PublishScheduledBlogPost;
use App\Enums\BlogPostStatus;
use App\Models\BlogPost;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publish every scheduled blog post whose publication time has arrived (story 0064), run every minute
 * by the schedule entry in routes/console.php.
 *
 * The command owns the SELECTION (one indexed query) and the loop; PublishScheduledBlogPost owns the
 * transition, one post at a time, so a bad row costs one post and not the batch (D-4). A failure is
 * reported and the run carries on, and the exit code stays 0 either way: a non-zero exit would make the
 * scheduler treat a single stuck post as a failed run, every minute, for as long as the row stays bad.
 *
 * The query never uses withTrashed() (D-9) and never chunk() (D-15): the sweep mutates the column its own
 * WHERE clause filters on, so an OFFSET-paginated chunk would skip rows once the backlog spans more than
 * one page. It has no actor, so it authorizes nothing (D-5).
 */
#[Signature('blog:publish-scheduled-posts {--dry-run : List the scheduled blog posts that are due without publishing them}')]
#[Description('Publish the scheduled blog posts whose publication time has arrived')]
class PublishScheduledBlogPosts extends Command
{
    public function handle(PublishScheduledBlogPost $publishScheduledBlogPost): int
    {
        $dueIds = BlogPost::query()
            ->where('status', BlogPostStatus::Scheduled)
            ->where('published_at', '<=', now())
            ->orderBy('published_at')
            ->pluck('id');

        if ($dueIds->isEmpty()) {
            $this->info('No scheduled blog posts are due.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('Dry run: %d scheduled blog %s.', $dueIds->count(), $dueIds->count() === 1 ? 'post is due' : 'posts are due'));
            $dueIds->each(fn (string $id) => $this->line($id));

            return self::SUCCESS;
        }

        $published = 0;
        $failed = 0;

        foreach ($dueIds as $id) {
            try {
                if ($publishScheduledBlogPost($id) !== null) {
                    $published++;
                }
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
            }
        }

        $this->info(sprintf('Published %d scheduled blog %s.', $published, $published === 1 ? 'post' : 'posts'));

        if ($failed > 0) {
            $this->error(sprintf('%d failed; see the log.', $failed));
        }

        Log::info('Scheduled blog post sweep finished', ['published' => $published, 'failed' => $failed]);

        return self::SUCCESS;
    }
}
