<?php

use App\Models\BlogTag;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

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

test('creating and re-fetching a blog tag persists name and populates both timestamps', function () {
    $tag = BlogTag::factory()->create(['name' => 'running']);

    $fresh = $tag->fresh();

    expect($fresh->name)->toBe('running')
        ->and($fresh->created_at)->not->toBeNull()
        ->and($fresh->updated_at)->not->toBeNull();
});

test('name is mass-assignable but normalized_name is not, so a forged value is replaced by the derived one', function () {
    $tag = BlogTag::create(['name' => 'running', 'normalized_name' => 'hijacked']);

    expect($tag->fresh()->normalized_name)->toBe('running');
});

test('the saving hook derives normalized_name on insert and re-derives it on a rename', function () {
    $tag = BlogTag::create(['name' => 'Niño']);

    expect($tag->fresh()->normalized_name)->toBe('nino');

    $tag->update(['name' => 'Trail  Running']);

    expect($tag->fresh()->normalized_name)->toBe('trail running');
});

test('saving a blog tag without touching name does not rewrite normalized_name', function () {
    $tag = BlogTag::create(['name' => 'running']);

    // A sentinel no fold could ever produce: if the hook re-derived it on this unrelated save, the
    // value would flip back to 'running'.
    DB::table('blog_tags')->where('id', $tag->id)->update(['normalized_name' => 'sentinel']);

    $fresh = $tag->fresh();
    $fresh->updated_at = now()->addMinute();
    $fresh->save();

    expect($fresh->fresh()->normalized_name)->toBe('sentinel');
});

test('a directly assigned normalized_name is overwritten by the derived one on save', function () {
    $tag = BlogTag::create(['name' => 'running']);

    $tag->normalized_name = 'hijacked';
    $tag->save();

    expect($tag->fresh()->normalized_name)->toBe('running');
});

test('blog tag does not use SoftDeletes', function () {
    expect(class_uses_recursive(BlogTag::class))->not->toContain(SoftDeletes::class);
});

test('blog_tags carries exactly the specified columns and no unique index on name', function () {
    $columns = Schema::getColumnListing('blog_tags');
    sort($columns);

    expect($columns)->toBe(['created_at', 'id', 'name', 'normalized_name', 'updated_at']);

    $indexes = collect(Schema::getIndexes('blog_tags'))->keyBy('name');

    expect($indexes->keys()->sort()->values()->all())->toBe(['blog_tags_normalized_name_unique', 'primary'])
        ->and($indexes['blog_tags_normalized_name_unique']['columns'])->toBe(['normalized_name'])
        ->and($indexes['blog_tags_normalized_name_unique']['unique'])->toBeTrue();
});

test('the length constants match the columns they guard', function () {
    $columns = collect(Schema::getColumns('blog_tags'))->keyBy('name');

    expect(BlogTag::NAME_MAX_LENGTH)->toBe(100)
        ->and($columns['name']['type'])->toBe('varchar(100)')
        ->and(BlogTag::NORMALIZED_NAME_MAX_LENGTH)->toBe(255)
        ->and($columns['normalized_name']['type'])->toBe('varchar(255)');
});
