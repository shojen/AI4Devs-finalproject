<?php

namespace App\Concerns;

use App\Enums\BlogPostStatus;
use App\Models\BlogPost;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every leaf method is named after the FIELD, not the model, so none collides with the sibling
 * `nameRules()` of BlogCategoryValidationRules / BlogTagValidationRules if a caller ever composes
 * more than one of the traits (story 0063's editor could): PHP fatals on a conflicting method.
 */
trait BlogPostValidationRules
{
    /**
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function titleRules(): array
    {
        return ['required', 'string', 'max:'.BlogPost::TITLE_MAX_LENGTH];
    }

    /**
     * Refuses a title whose slug is unusable: empty (a title of only punctuation or emoji), wider
     * than the `slug` column (Str::slug() transliterates, so it is not length-preserving), or already
     * held by ANOTHER post -- trashed ones included, because a trashed post keeps its slug reserved
     * (D-7b) and the check must see the same rows the unique index does. The slug is derived, never
     * submitted, so the refusal is keyed on `title`, the field the editor can change (OQ-2).
     *
     * $ignoreBlogPostId excludes the post being edited, so re-saving a post under its own title
     * succeeds. This is a pre-flight only: the unique index has the last word under a race.
     *
     * @return array<int, Closure>
     */
    protected function titleSlugRules(?string $ignoreBlogPostId = null): array
    {
        return [
            function (string $attribute, mixed $value, Closure $fail) use ($ignoreBlogPostId): void {
                if (! is_string($value)) {
                    return;
                }

                $slug = Str::slug($value);

                if ($slug === '') {
                    $fail(trans('validation.required', ['attribute' => $attribute]));

                    return;
                }

                if (mb_strlen($slug) > BlogPost::TITLE_MAX_LENGTH) {
                    $fail(trans('validation.max.string', [
                        'attribute' => $attribute,
                        'max' => BlogPost::TITLE_MAX_LENGTH,
                    ]));

                    return;
                }

                $taken = BlogPost::withTrashed()
                    ->where('slug', $slug)
                    ->when($ignoreBlogPostId !== null, fn ($query) => $query->whereKeyNot($ignoreBlogPostId))
                    ->exists();

                if ($taken) {
                    $fail(trans('validation.unique', ['attribute' => $attribute]));
                }
            },
        ];
    }

    /**
     * `Rule::exists()`, not a bare string, so an unknown id is a form error rather than a raw
     * QueryException from the FK; the constraint is the last-word backstop behind this rule.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function blogCategoryIdRules(): array
    {
        return ['required', 'string', Rule::exists('blog_categories', 'id')];
    }

    /**
     * `Rule::enum()` keeps a forged value a ValidationException instead of the uncaught \ValueError
     * an enum cast raises (task 0015's finding F8) -- so callers hand this a STRING, never a typed
     * enum property.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function statusRules(): array
    {
        return ['required', Rule::enum(BlogPostStatus::class)];
    }

    /**
     * Refuses a raw body larger than the sanitizer will read. symfony/html-sanitizer TRUNCATES input
     * past `html-sanitizer.max_input_length` rather than rejecting it, so an oversized Published
     * body would be silently cut off, possibly mid-tag. Checked on the raw submission, before
     * sanitizing, so the limit is a visible validation error instead of lost content (D-4b).
     *
     * @throws ValidationException
     */
    protected function assertBodyWithinSanitizerLimit(?string $rawBody): void
    {
        $max = (int) config('html-sanitizer.max_input_length', -1);

        if ($rawBody !== null && $max > 0 && strlen(trim($rawBody)) > $max) {
            throw ValidationException::withMessages([
                'body' => trans('validation.max.string', ['attribute' => 'body', 'max' => $max]),
            ]);
        }
    }

    /**
     * The body rule is decided by the submitted status: a Draft may be saved before anything is
     * written, while a Published or Scheduled post may not (D-4). A `match` on the typed parameter,
     * deliberately not `required_if:status,published`: the status is an argument of the action, not
     * a key of the validated array, and `required_if` would silently fail OPEN when that key is
     * absent.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function bodyRules(BlogPostStatus $status): array
    {
        return match ($status) {
            BlogPostStatus::Draft => ['nullable', 'string'],
            BlogPostStatus::Published,
            BlogPostStatus::Scheduled => ['required', 'string'],
        };
    }

    /**
     * The date rule is decided by the submitted status (D-6). `after:now` is strictly `>`: a date
     * equal to the current instant is already publishable, not scheduled.
     *
     * A Published post's rule set has no upper bound short of the TIMESTAMP ceiling on purpose: a future
     * date is not refused here but resolved by the actions (story 0061a) -- a new or not-yet-live post is
     * stored as Scheduled, an already-live one is refused -- because a rule cannot change the status
     * it is validating.
     *
     * $enforceFuture is false only for a Scheduled post being re-saved with the very date it already
     * carries: an editor retitling an overdue scheduled post resubmits the stored, now-past date,
     * and an unconditional `after:now` would make every such edit impossible.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function publishedAtRules(BlogPostStatus $status, bool $enforceFuture = true): array
    {
        return match ($status) {
            BlogPostStatus::Draft => ['prohibited'],
            BlogPostStatus::Scheduled => $enforceFuture
                ? ['required', 'date', 'after:now', $this->publishedAtCeiling()]
                : ['required', 'date', $this->publishedAtCeiling()],
            BlogPostStatus::Published => ['nullable', 'date', $this->publishedAtFloor(), $this->publishedAtCeiling()],
        };
    }

    /**
     * `published_at` is a MySQL TIMESTAMP: a date past 2038-01-19 would be accepted by `date` and then
     * fail the INSERT with a raw 1292 error. Refused here as a form error instead.
     */
    private function publishedAtCeiling(): string
    {
        return 'before:'.BlogPost::PUBLISHED_AT_BEFORE;
    }

    /**
     * The TIMESTAMP floor, for the same reason as the ceiling: only a Published post may carry a past
     * date, so only it can reach 1970.
     */
    private function publishedAtFloor(): string
    {
        return 'after:'.BlogPost::PUBLISHED_AT_AFTER;
    }

    /**
     * The whole submitted tag set: a list of at most BlogPost::MAX_TAGS non-blank strings. Each name is resolved (and its
     * format validated) by FindOrCreateBlogTag, so this only refuses what would otherwise be
     * silently reinterpreted.
     *
     * @return array<int, ValidationRule|Closure|array<mixed>|string>
     */
    protected function tagNamesRules(): array
    {
        return [
            'array',
            'max:'.BlogPost::MAX_TAGS,
            function (string $attribute, mixed $value, Closure $fail): void {
                foreach ((array) $value as $name) {
                    if (! is_string($name) || trim($name) === '') {
                        $fail(trans('validation.array', ['attribute' => $attribute]));

                        return;
                    }
                }
            },
        ];
    }
}
