<?php

// STUB — replaced by layer 2

namespace App\Livewire\BlogPosts;

use App\Models\BlogPost;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Placeholder for the routed post create/edit screen (story 0063, layer 2 replaces this class
 * entirely). It exists so the three post routes resolve today: it authorizes the finer ability the
 * route's `can:blog.view` gate does not (`create` for blog-posts.create, `update` for
 * blog-posts.edit) and renders only the topbar heading.
 */
#[Title('Blog post editor')]
class Editor extends Component
{
    #[Locked]
    public ?string $blogPostId = null;

    public function mount(?BlogPost $blogPost = null): void
    {
        if ($blogPost === null) {
            Gate::authorize('create', BlogPost::class);

            return;
        }

        Gate::authorize('update', $blogPost);

        $this->blogPostId = $blogPost->id;
    }
}
