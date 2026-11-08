<?php

use App\Actions\Products\SanitizeProductDescription;
use App\Actions\Translations\SetTranslation;
use App\Models\BlogPost;
use App\Models\BlogPostTranslation;
use App\Models\StoreLanguage;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

// Story 0078 (D-4, D-8, D-9): BlogPostTranslation's slug-derivation hook, its second sanitizer layer,
// its mass-assignment guard and its relations. The hook limits are pinned, not endorsed.

test('the slug is derived from the title on insert and re-derived when the title changes', function () {
    $translation = BlogPostTranslation::factory()->create(['title' => 'Botas de invierno']);

    expect($translation->fresh()->slug)->toBe('botas-de-invierno');

    $translation->update(['title' => 'Botas de Invierno 2026']);

    expect($translation->fresh()->slug)->toBe('botas-de-invierno-2026');
});

test('saving without touching the title does not rewrite the slug', function () {
    $translation = BlogPostTranslation::factory()->create(['title' => 'Botas de invierno']);

    // Plant a slug the hook would never derive, then save an unrelated column.
    DB::table('blog_post_translations')->where('id', $translation->id)->update(['slug' => 'planted']);

    $fresh = $translation->fresh();
    $fresh->body = '<p>Solo el cuerpo</p>';
    $fresh->save();

    expect($fresh->fresh()->slug)->toBe('planted')
        ->and($fresh->fresh()->body)->toBe('<p>Solo el cuerpo</p>');
});

test('a slug forced together with a dirty title is overwritten by the derived one', function () {
    $translation = BlogPostTranslation::factory()->create(['title' => 'Primero']);

    $translation->forceFill(['title' => 'Botas', 'slug' => 'evil'])->save();

    expect($translation->fresh()->slug)->toBe('botas');
});

test('a slug forced without touching the title is overwritten by the one derived from the title', function () {
    $translation = BlogPostTranslation::factory()->create(['title' => 'Botas']);

    $translation->forceFill(['slug' => 'forced'])->save();

    expect($translation->fresh()->slug)->toBe('botas');
});

test('saving a body longer than the sanitizer input limit throws instead of silently truncating it', function () {
    $max = (int) config('html-sanitizer.max_input_length');
    $translation = BlogPostTranslation::factory()->create(['body' => '<p>original</p>']);

    $translation->body = '<p>'.str_repeat('a', $max).'</p>';

    expect(fn () => $translation->save())->toThrow(LogicException::class)
        ->and($translation->fresh()->body)->toBe('<p>original</p>');
});

test('inserting a body longer than the sanitizer input limit throws and writes no row', function () {
    $max = (int) config('html-sanitizer.max_input_length');
    $before = DB::table('blog_post_translations')->count();

    expect(fn () => BlogPostTranslation::factory()->create(['body' => '<p>'.str_repeat('a', $max).'</p>']))
        ->toThrow(LogicException::class)
        ->and(DB::table('blog_post_translations')->count())->toBe($before);
});

test('a bare SetTranslation call with a title that has no usable slug stores an empty slug, which pins the missing guard', function () {
    $language = StoreLanguage::factory()->default()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();

    app(SetTranslation::class)($post, $language, ['title' => '!!!', 'body' => null]);

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->value('slug'))->toBe('');
});

test('a slug supplied to a bare SetTranslation call is ignored: the derived one is stored', function () {
    $language = StoreLanguage::factory()->default()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();

    app(SetTranslation::class)($post, $language, ['title' => 'Botas', 'slug' => 'evil']);

    expect(DB::table('blog_post_translations')->where('blog_post_id', $post->id)->value('slug'))->toBe('botas');
});

test('a body written through a bare SetTranslation call is persisted sanitized, with no blog action in the path', function () {
    $language = StoreLanguage::factory()->default()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();

    app(SetTranslation::class)($post, $language, [
        'title' => 'Directo',
        'body' => '<p>kept</p><script>alert(1)</script>',
    ]);

    $stored = DB::table('blog_post_translations')->where('blog_post_id', $post->id)->value('body');

    expect($stored)->not->toContain('script')
        ->and($stored)->not->toContain('alert(1)')
        ->and($stored)->toContain('<p>kept</p>');
});

test('a body assigned directly on the model is sanitized on insert and again on update', function () {
    $translation = BlogPostTranslation::factory()->create(['body' => '<p>ok</p><script>x()</script>']);

    expect($translation->fresh()->body)->not->toContain('script');

    $translation->update(['body' => '<img src="javascript:alert(1)"><p>again</p><script>y()</script>']);

    expect($translation->fresh()->body)->not->toContain('script')
        ->and($translation->fresh()->body)->not->toContain('javascript:')
        ->and($translation->fresh()->body)->toContain('<p>again</p>');
});

test('a null body stays null through the hook', function () {
    $translation = BlogPostTranslation::factory()->create(['body' => null]);

    expect($translation->fresh()->body)->toBeNull();
});

test('sanitizing is idempotent across the action layer and the hook layer', function () {
    $sanitize = app(SanitizeProductDescription::class);
    $once = $sanitize('<p onclick="x()">a <strong>b</strong></p><script>z()</script><a href="javascript:q()">l</a>');

    $translation = BlogPostTranslation::factory()->create(['body' => $once]);

    expect($translation->fresh()->body)->toBe($once);
});

test('saving without touching the body does not rewrite it (the isDirty guard)', function () {
    $translation = BlogPostTranslation::factory()->create(['body' => '<p>clean</p>']);

    // A value no sanitizer would keep: if the hook re-sanitized on this unrelated save it would vanish.
    DB::table('blog_post_translations')->where('id', $translation->id)->update(['body' => '<script>sentinel</script>']);

    $fresh = $translation->fresh();
    $fresh->title = 'Renamed only';
    $fresh->save();

    expect($fresh->fresh()->title)->toBe('Renamed only')
        ->and($fresh->fresh()->body)->toBe('<script>sentinel</script>');
});

test('only title and body are fillable, and a forged blog_post_id, store_language_id or slug is ignored', function () {
    $post = BlogPost::factory()->withoutTranslations()->create();
    $victim = BlogPost::factory()->withoutTranslations()->create();
    $language = StoreLanguage::factory()->default()->create();
    $otherLanguage = StoreLanguage::factory()->create();

    expect((new BlogPostTranslation)->getFillable())->toBe(['title', 'body']);

    $translation = new BlogPostTranslation([
        'title' => 'Forjado',
        'slug' => 'evil',
        'blog_post_id' => $victim->id,
        'store_language_id' => $otherLanguage->id,
    ]);

    expect($translation->blog_post_id)->toBeNull()
        ->and($translation->store_language_id)->toBeNull()
        ->and($translation->slug)->toBeNull()
        ->and($translation->title)->toBe('Forjado');

    $translation->forceFill(['blog_post_id' => $post->id, 'store_language_id' => $language->id])->save();

    expect($translation->fresh()->blog_post_id)->toBe($post->id)
        ->and($translation->fresh()->store_language_id)->toBe($language->id)
        ->and($translation->fresh()->slug)->toBe('forjado');
});

test('a translation belongs to its post, trashed posts included, and to its store language, and is not soft-deletable', function () {
    $language = StoreLanguage::factory()->default()->create();
    $post = BlogPost::factory()->withoutTranslations()->create();
    $translation = BlogPostTranslation::factory()->forLanguage($language)->create(['blog_post_id' => $post->id]);

    $post->delete();

    $fresh = $translation->fresh();

    expect($fresh->blogPost())->toBeInstanceOf(BelongsTo::class)
        ->and($fresh->blogPost->id)->toBe($post->id)
        ->and($fresh->storeLanguage())->toBeInstanceOf(BelongsTo::class)
        ->and($fresh->storeLanguage->id)->toBe($language->id)
        ->and(class_uses_recursive(BlogPostTranslation::class))->not->toContain(SoftDeletes::class);
});
