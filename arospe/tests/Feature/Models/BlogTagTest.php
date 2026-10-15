<?php

use App\Models\BlogTag;
use App\Models\BlogTagTranslation;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Story 0074 (D-2): BlogTag is identity-only -- its names live in blog_tag_translations, whose own
// behaviour (the derivation hook, uniqueness, cascade) is pinned in BlogTagTranslationTest.php.

test('a factory-created blog tag receives a uuidv7 string primary key', function () {
    $tag = BlogTag::factory()->create();

    expect($tag->id)->toBeString()
        ->and(Str::isUuid($tag->id, 7))->toBeTrue();
});

test('two blog tags created in immediate succession sort lexicographically in creation order', function () {
    $first = BlogTag::factory()->create();
    $second = BlogTag::factory()->create();

    expect(strcmp($first->id, $second->id))->toBeLessThan(0);
});

test('creating and re-fetching a blog tag populates both timestamps', function () {
    $fresh = BlogTag::factory()->create()->fresh();

    expect($fresh->created_at)->not->toBeNull()
        ->and($fresh->updated_at)->not->toBeNull();
});

test('no attribute is mass-assignable, so a forged name or normalized_name cannot reach the parent row', function () {
    expect(fn () => BlogTag::create(['name' => 'running']))->toThrow(MassAssignmentException::class)
        ->and(fn () => BlogTag::create(['normalized_name' => 'hijacked']))->toThrow(MassAssignmentException::class)
        ->and(BlogTag::count())->toBe(0);
});

test('the factory writes one default-language translation by default and none when asked not to', function () {
    $named = BlogTag::factory()->named('running')->create();
    $bare = BlogTag::factory()->withoutTranslations()->create();

    expect(BlogTagTranslation::where('blog_tag_id', $named->id)->sole()->name)->toBe('running')
        ->and($named->fresh()->translated('name'))->toBe('running')
        ->and(BlogTagTranslation::where('blog_tag_id', $bare->id)->count())->toBe(0)
        ->and($bare->fresh()->translated('name'))->toBeNull();
});

test('blog tag does not use SoftDeletes', function () {
    expect(class_uses_recursive(BlogTag::class))->not->toContain(SoftDeletes::class);
});

test('blog_tags carries exactly the identity columns and no name column or name index', function () {
    $columns = Schema::getColumnListing('blog_tags');
    sort($columns);

    expect($columns)->toBe(['created_at', 'id', 'updated_at']);

    $indexes = collect(Schema::getIndexes('blog_tags'))->pluck('name')->sort()->values()->all();

    expect($indexes)->toBe(['primary']);
});

test('the length constants match the translation columns they guard', function () {
    $columns = collect(Schema::getColumns('blog_tag_translations'))->keyBy('name');

    expect(BlogTag::NAME_MAX_LENGTH)->toBe(100)
        ->and($columns['name']['type'])->toBe('varchar(100)')
        ->and(BlogTag::NORMALIZED_NAME_MAX_LENGTH)->toBe(255)
        ->and($columns['normalized_name']['type'])->toBe('varchar(255)');
});
