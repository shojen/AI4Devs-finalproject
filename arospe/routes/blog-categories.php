<?php

use App\Livewire\BlogCategories\Index as BlogCategoriesIndex;   // aliased: `Index` is ambiguous across areas now
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    // `can:blog.view`, not Spatie's `permission:` — same reason as users.index /
    // roles.index / sales-regions.index / blog-tags.index: Livewire 4's
    // PersistentMiddleware allowlist carries Laravel's `Authorize` (`can:`) but
    // not Spatie's `PermissionMiddleware`, so a `permission:`-gated route would
    // protect the initial GET only, leaving every save()/deleteCategory()
    // /livewire/update round-trip unauthorized. See docs/architecture/authorization.md.
    Route::livewire('blog/categories', BlogCategoriesIndex::class)
        ->middleware(['can:blog.view'])
        ->name('blog-categories.index');
});
