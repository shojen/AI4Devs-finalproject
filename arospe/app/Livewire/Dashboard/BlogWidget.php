<?php

namespace App\Livewire\Dashboard;

use App\Actions\Dashboard\GetLatestBlogPosts;
use App\Concerns\ChecksAbilitiesSafely;
use App\Enums\BlogPostStatus;
use App\Models\BlogPost;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Dashboard widget: the latest published or scheduled blog posts (story 0083). Read-only: no public
 * property and no mutating method, so there is nothing to forge from the client.
 *
 * The widget checks `blog.view` itself before calling its action (the action would throw and log a
 * refusal). Rows link to the editor only when the actor may update posts, evaluated once per widget:
 * BlogPostPolicy::update ignores its target, so a fresh instance stands for every row.
 */
class BlogWidget extends Component
{
    use ChecksAbilitiesSafely;

    /**
     * @return array{rows: list<array{id: string, title: ?string, description: string, status: BlogPostStatus, publishAt: ?CarbonImmutable}>, canEdit: bool}
     */
    #[Computed]
    public function posts(): array
    {
        if (! $this->allowsSafely('viewAny', BlogPost::class)) {
            return ['rows' => [], 'canEdit' => false];
        }

        return [
            'rows' => app(GetLatestBlogPosts::class)(),
            'canEdit' => $this->allowsSafely('update', new BlogPost),
        ];
    }
}
