<?php

use App\Actions\Translations\SetTranslation;

// Story 0079 (D-8): only the self-authorizing SetBlogPostTranslation may call the unauthorized,
// unvalidated SetTranslation primitive. One expect() per namespace -- expect([...]) evaluates
// disjunctively.

arch('App\Livewire\BlogPosts never uses the SetTranslation primitive')
    ->expect('App\Livewire\BlogPosts')
    ->not->toUse(SetTranslation::class);
