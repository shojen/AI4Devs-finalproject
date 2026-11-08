<?php

use App\Enums\BlogPostStatus;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Story 0078 (D-15): BlogPostFactory writes the default-language translation in an identifiable
// afterCreating step, and its titled()/withoutTranslations() states change ONLY that step, so
// withTags() (itself an afterCreating callback) and the status states survive in any order.

test('a plain create produces a titled post through exactly one default-language translation', function () {
    $post = BlogPost::factory()->create();

    $default = StoreLanguage::query()->where('is_default', true)->sole();
    $translations = BlogPostTranslation::query()->where('blog_post_id', $post->id)->get();

    expect($translations)->toHaveCount(1)
        ->and($translations[0]->store_language_id)->toBe($default->id)
        ->and($translations[0]->title)->not->toBe('')
        ->and($translations[0]->slug)->not->toBe('')
        ->and($translations[0]->body)->toStartWith('<p>');
});

test('many default-created posts never collide on the per-language slug', function () {
    BlogPost::factory()->count(15)->create();

    expect(DB::table('blog_post_translations')->count())->toBe(15)
        ->and(DB::table('blog_post_translations')->distinct()->count('slug'))->toBe(15);
});

test('titled() writes the given title and body into the default-language translation', function () {
    $post = BlogPost::factory()->titled('Botas de invierno', '<p>Cuerpo</p>')->create();

    $row = DB::table('blog_post_translations')->where('blog_post_id', $post->id)->sole();

    expect($row->title)->toBe('Botas de invierno')
        ->and($row->slug)->toBe('botas-de-invierno')
        ->and($row->body)->toBe('<p>Cuerpo</p>')
        ->and($row->store_language_id)->toBe(StoreLanguage::query()->where('is_default', true)->sole()->id);
});

test('titled() without a body stores a null body', function () {
    $post = BlogPost::factory()->titled('Sin cuerpo')->create();

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->sole()->body)->toBeNull();
});

test('chaining titled() twice leaves exactly one translation carrying the last title', function () {
    $post = BlogPost::factory()->titled('Primero')->titled('Segundo')->create();

    $rows = DB::table('blog_post_translations')->where('blog_post_id', $post->id)->get();

    expect($rows)->toHaveCount(1)->and($rows[0]->title)->toBe('Segundo');
});

test('withoutTranslations() creates a bare parent with no translation row', function () {
    $post = BlogPost::factory()->withoutTranslations()->create();

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->count())->toBe(0);
});

test('published()->titled() and titled()->published() both keep the published status and the title', function () {
    $first = BlogPost::factory()->published()->titled('Uno')->create();
    $second = BlogPost::factory()->titled('Dos')->published()->create();

    foreach ([[$first, 'Uno'], [$second, 'Dos']] as [$post, $title]) {
        $fresh = $post->fresh();

        expect($fresh->status)->toBe(BlogPostStatus::Published)
            ->and($fresh->published_at)->not->toBeNull()
            ->and(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->sole()->title)->toBe($title);
    }
});

test('scheduled(), createdBy() and withoutTranslations() keep their effects together', function () {
    $user = User::factory()->create();

    $post = BlogPost::factory()->scheduled()->createdBy($user)->withoutTranslations()->create();

    expect($post->fresh()->status)->toBe(BlogPostStatus::Scheduled)
        ->and($post->fresh()->created_by)->toBe($user->id)
        ->and(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->count())->toBe(0);
});

test('withTags(2)->titled() yields both the two tags and the title, in either order', function () {
    $first = BlogPost::factory()->withTags(2)->titled('X')->create();
    $second = BlogPost::factory()->titled('Y')->withTags(2)->create();

    foreach ([[$first, 'X'], [$second, 'Y']] as [$post, $title]) {
        expect(DB::table('blog_post_tag')->where('blog_post_id', $post->id)->count())->toBe(2)
            ->and(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->sole()->title)->toBe($title);
    }
});

test('withTags(2)->withoutTranslations() and the reverse yield the tags and zero translations', function () {
    $first = BlogPost::factory()->withTags(2)->withoutTranslations()->create();
    $second = BlogPost::factory()->withoutTranslations()->withTags(2)->create();

    foreach ([$first, $second] as $post) {
        expect(DB::table('blog_post_tag')->where('blog_post_id', $post->id)->count())->toBe(2)
            ->and(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->count())->toBe(0);
    }
});

test('with no default store language the default step creates exactly one, and later posts never duplicate it', function () {
    $categoryId = BlogCategory::factory()->withoutTranslations()->create()->id;

    expect(StoreLanguage::query()->count())->toBe(0);

    BlogPost::factory()->create(['blog_category_id' => $categoryId]);

    expect(StoreLanguage::query()->count())->toBe(1)
        ->and(StoreLanguage::query()->where('is_default', true)->count())->toBe(1);

    BlogPost::factory()->create(['blog_category_id' => $categoryId]);
    BlogPost::factory()->titled('Otro')->create(['blog_category_id' => $categoryId]);

    expect(StoreLanguage::query()->count())->toBe(1);
});

test('forging title, body or slug as a create() attribute fails loudly because they are no longer columns', function () {
    expect(fn () => BlogPost::factory()->create(['title' => 'Forjado']))
        ->toThrow(fn (QueryException $exception) => expect($exception->errorInfo[1])->toBe(1054));
});

test('BlogPostTranslation::factory()->forLanguage() binds the row to that language and derives the slug', function () {
    $language = StoreLanguage::factory()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();

    $translation = BlogPostTranslation::factory()->forLanguage($language)->create([
        'blog_post_id' => $post->id,
        'title' => 'Botas de invierno',
    ]);

    expect($translation->store_language_id)->toBe($language->id)
        ->and($translation->blog_post_id)->toBe($post->id)
        ->and($translation->fresh()->slug)->toBe('botas-de-invierno');
});
