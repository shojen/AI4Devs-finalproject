<?php

// Story 0063 -- one area file, three routes in one ['auth', 'verified'] group. Both components
// import ALIASED: `Index` is ambiguous across several areas and `Editor` across two.
use App\Livewire\BlogPosts\Editor as BlogPostsEditor;
use App\Livewire\BlogPosts\Index as BlogPostsIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // `can:blog.view`, not Spatie's `permission:` -- same reason as users.index /
    // roles.index / sales-regions.index / blog-tags.index: Livewire 4's
    // PersistentMiddleware allowlist carries Laravel's `Authorize` (`can:`) but not
    // Spatie's `PermissionMiddleware`, so a `permission:`-gated route would protect
    // the initial GET only, leaving every save()/deleteBlogPost() /livewire/update
    // round-trip unauthorized. See docs/architecture/authorization.md.
    //
    // All three routes gate on blog.view; the finer abilities (create / update) are
    // authorized inside BlogPostsEditor, and delete / restore inside BlogPostsIndex.
    Route::livewire('blog/posts', BlogPostsIndex::class)
        ->middleware(['can:blog.view'])->name('blog-posts.index');
    Route::livewire('blog/posts/create', BlogPostsEditor::class)
        ->middleware(['can:blog.view'])->name('blog-posts.create');
    Route::livewire('blog/posts/{blogPost}/edit', BlogPostsEditor::class)
        ->middleware(['can:blog.view'])->name('blog-posts.edit');
});
