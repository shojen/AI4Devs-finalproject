<?php

use App\Livewire\BlogTags\Index as BlogTagsIndex;   // aliased: `Index` is ambiguous across areas now
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // `can:blog.view`, not Spatie's `permission:` — same reason as users.index /
    // roles.index / sales-regions.index: Livewire 4's PersistentMiddleware
    // allowlist carries Laravel's `Authorize` (`can:`) but not Spatie's
    // `PermissionMiddleware`, so a `permission:`-gated route would protect
    // the initial GET only, leaving every save()/deleteTag() /livewire/update
    // round-trip unauthorized. See docs/architecture/authorization.md.
    Route::livewire('blog/tags', BlogTagsIndex::class)
        ->middleware(['can:blog.view'])
        ->name('blog-tags.index');
});
