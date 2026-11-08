<?php

use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;

// Story 0078 (0068 D8): blog_post_translations is registered in config/store-languages.php, so the
// removal warning counts blog post content. Uses the SHIPPED registry, never a stand-in.

test('the shipped registry carries the blog_post_translations entry', function () {
    expect(config('store-languages.translation_relations'))
        ->toContain(['table' => 'blog_post_translations', 'column' => 'store_language_id']);
});

test('translationUsageCount counts the French blog post translations and only those', function () {
    $default = StoreLanguage::factory()->default()->create();
    $fr = StoreLanguage::factory()->create();
    $postA = BlogPost::factory()->withoutTranslations()->create();
    $postB = BlogPost::factory()->withoutTranslations()->create();
    // The post factory also creates a category, which carries its own default-language translation:
    // that row is not a blog post translation, so it must not be counted, and the baseline is read
    // from the table instead of hard-coding what the factories create.
    $defaultBaseline = StoreLanguage::translationUsageCount($default->id);

    BlogPostTranslation::factory()->forLanguage($fr)->create(['blog_post_id' => $postA->id]);
    BlogPostTranslation::factory()->forLanguage($fr)->create(['blog_post_id' => $postB->id]);
    BlogPostTranslation::factory()->forLanguage($default)->create(['blog_post_id' => $postA->id]);

    expect(StoreLanguage::translationUsageCount($fr->id))->toBe(2)
        ->and(StoreLanguage::translationUsageCount($default->id) - $defaultBaseline)->toBe(1);
});

test('translations of a trashed post still count towards the language usage', function () {
    $fr = StoreLanguage::factory()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();
    BlogPostTranslation::factory()->forLanguage($fr)->create(['blog_post_id' => $post->id]);

    $post->delete();

    expect(StoreLanguage::translationUsageCount($fr->id))->toBe(1);
});
