<?php

namespace App\Notifications;

use App\Enums\BlogPostPublishFailureStage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Story 0064b -- tells the post's creator (or, failing that, the blog.edit administrators, D-8) that
 * the scheduled sweep could not take their post live, or could not send its follow-up announcement.
 * See App\Actions\Blog\NotifyScheduledBlogPostPublishFailed for recipient resolution, classification
 * (D-2) and dedup (D-6).
 *
 * `data` is exactly three keys (D-4): `blog_post_id`, `title` (a frozen snapshot, read in exactly one
 * place -- a rename after the failure is a new fact, not something this notification retroactively
 * reflects) and `stage` (the enum's string value, so a future bell arm, H-3, can branch without
 * knowing this class). No `type` discriminator (Laravel already writes the FQCN into
 * `notifications.type`). No exception message, class, SQL or stack frame ever reaches here -- the
 * notifier that builds this class never receives the original Throwable at all (D-3).
 *
 * No SerializesModels and no model in the constructor, unlike App\Notifications\CustomerCreated /
 * OrderCreated (whose SerializesModels reasoning is about a FUTURE queued form): this one already
 * IS queued (D-5), so it takes primitives from the start, which serialise exactly into the `jobs`
 * payload and cannot be rehydrated stale or against a post deleted in between.
 *
 * Privacy caveat, stated rather than hidden: `notifications.data` keeps the title with no erasure
 * path, the same position CustomerCreated's `customer_name` is already in (D-4).
 */
class ScheduledBlogPostPublishFailed extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The longest title this notification will ever show, after whitespace is collapsed (D-9). Caps a
     * pathological title rather than letting it balloon the subject or the mail transport.
     */
    private const MAX_TITLE_LENGTH = 150;

    public function __construct(
        public readonly string $blogPostId,
        public readonly ?string $title,
        public readonly BlogPostPublishFailureStage $stage,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    /**
     * Phase 4 security audit finding L-1 (non-blocking, applied ahead of Phase 5): re-checks the
     * identical D-8 recipient predicate at DELIVERY time, per channel, not only at dispatch time.
     *
     * `App\Actions\Blog\NotifyScheduledBlogPostPublishFailed::recipients()` checks the predicate once,
     * before this (queued) notification is ever pushed. Between that dispatch and the queue worker
     * actually processing the job, `Illuminate\Database\Eloquent\Model::newQueryForRestoration()`
     * rehydrates the notifiable via `newQueryWithoutScopes()->whereKey($ids)` -- deliberately bypassing
     * every global scope, including `SoftDeletingScope` -- so a recipient who is soft-deleted,
     * suspended, or loses `blog.edit` during that queue-latency window would otherwise still receive
     * mail (to a soft-deleted user's obfuscated `deleted+{id}@deleted.invalid` address) or a database
     * notification disclosing the title to someone no longer entitled to it.
     *
     * Mirrors NotifyScheduledBlogPostPublishFailed::recipients()'s own creator-branch predicate exactly
     * (`isActive()`, non-empty email, `can('blog.edit')`), plus an explicit `trashed()` check that the
     * dispatch-time code gets for free from the creator relation's default SoftDeletingScope but this
     * method cannot rely on, since the notifiable here may have been restored without scopes.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User
            && ! $notifiable->trashed()
            && $notifiable->isActive()
            && trim((string) $notifiable->email) !== ''
            && $notifiable->can('blog.edit');
    }

    /**
     * @return array{blog_post_id: string, title: string|null, stage: string}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'blog_post_id' => $this->blogPostId,
            'title' => $this->title,
            'stage' => $this->stage->value,
        ];
    }

    /**
     * D-9/D-10: two absolute links (an email is read outside the app, so `url(route(...))` rather than
     * a relative path), one fixed line per stage, and the title escaped against Markdown injection
     * before it ever reaches ->line()/->subject() -- verified against the framework (V-7):
     * Illuminate\Mail\Markdown::render() HTML-encodes `<` and `>` but does NOT neutralise Markdown
     * syntax, and `Markdown::withSecuredEncoding()` is off project-wide, so an un-escaped
     * `[x](https://evil.example)` would render as a real, clickable link inside a trusted email. The
     * title is NEVER wrapped in HtmlString/Htmlable anywhere in this method -- that would defeat both
     * Blade's own `{{ }}` HTML-encoding and the Markdown-escaping below.
     *
     * The `subject` translation is deliberately reused as the FIRST body line too (with the title run
     * through escapeMarkdownCharacters() rather than the plain sanitised title): this is the one line
     * that "names" the post inside the email body itself, not only in the subject header (which some
     * mail clients render separately from, or instead of, the body) -- and it keeps the lang catalog at
     * exactly the six keys this story's copy plan names, rather than inventing a seventh.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $displayTitle = $this->sanitizedTitle();
        $lineKey = match ($this->stage) {
            BlogPostPublishFailureStage::Publish => 'line_publish',
            BlogPostPublishFailureStage::Announce => 'line_announce',
        };

        return (new MailMessage)
            ->subject(trans('notifications.mail.blog_post_publish_failed.subject', ['title' => $displayTitle]))
            ->line(trans('notifications.mail.blog_post_publish_failed.subject', [
                'title' => $this->escapeMarkdownCharacters($displayTitle),
            ]))
            ->line(trans("notifications.mail.blog_post_publish_failed.{$lineKey}"))
            ->action(
                trans('notifications.mail.blog_post_publish_failed.edit_button'),
                url(route('blog-posts.edit', $this->blogPostId)),
            )
            ->line(sprintf(
                '[%s](%s)',
                trans('notifications.mail.blog_post_publish_failed.all_posts_link'),
                url(route('blog-posts.index')),
            ));
    }

    /**
     * The title, safe to interpolate into a SINGLE-LINE, plain-text context (the subject header, which
     * is never parsed as Markdown): whitespace (including any embedded newline or other control
     * character) is collapsed to single spaces so a hostile title can never split the subject into two
     * lines or inject a mail header, and the result is length-capped. A null or blank title falls back
     * to the `untitled` copy -- never the literal word "null" or an empty pair of quotes.
     */
    private function sanitizedTitle(): string
    {
        $title = $this->title;

        if ($title === null || trim($title) === '') {
            return trans('notifications.mail.blog_post_publish_failed.untitled');
        }

        $withoutControlCharacters = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $title) ?? $title;
        $collapsed = trim((string) preg_replace('/\s+/u', ' ', $withoutControlCharacters));

        if ($collapsed === '') {
            return trans('notifications.mail.blog_post_publish_failed.untitled');
        }

        return mb_strlen($collapsed) > self::MAX_TITLE_LENGTH
            ? mb_substr($collapsed, 0, self::MAX_TITLE_LENGTH)
            : $collapsed;
    }

    /**
     * Backslash-escape every ASCII punctuation character CommonMark treats as the start of its own
     * syntax (emphasis, links/images, code spans, headings, lists, blockquotes, tables, thematic
     * breaks), so a title reaching the Markdown-rendered mail BODY can never be interpreted as
     * anything other than the literal characters it contains -- CommonMark drops a backslash placed
     * before an escapable character and renders the character itself, verbatim (R-4).
     *
     * `<`, `>`, `&`, `"` and `'` are deliberately NOT included here: those are already turned into HTML
     * entities by Blade's own `{{ }}` echo before this text ever reaches the Markdown parser (V-7), and
     * escaping them here too would interact badly with that separate encoding pass.
     */
    private function escapeMarkdownCharacters(string $text): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+!|.\-])/', '\\\\$1', $text) ?? $text;
    }
}
