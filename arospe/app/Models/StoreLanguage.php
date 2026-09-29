<?php

namespace App\Models;

use Database\Factories\StoreLanguageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A store-content-authoring language -- the admin-managed catalog behind the "add/remove a
 * language" half of PRD Epic 5, Layer 2 (story 0068). Independent of App\Enums\UiLocale /
 * App\Models\LocaleSetting, which govern the dashboard's own `en`/`es` interface language: a
 * store may author content in a language the dashboard does not even offer.
 *
 * @property string $id
 * @property string $code ISO 639-1 alpha-2, lowercase -- e.g. "es", "fr"
 * @property string $name the fixture's endonym -- e.g. "Español", "Français"
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([])]
class StoreLanguage extends Model
{
    /** @use HasFactory<StoreLanguageFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Scope a query to active entries.
     *
     * @param  Builder<StoreLanguage>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Story 0070 (D-10): memoised for the duration of the request/test, so a whole list render
     * costs one `store_languages` query rather than one per row. Lives here, on StoreLanguage --
     * never inside App\Concerns\HasTranslations -- because PHP gives each class consuming a
     * trait its OWN copy of that trait's static properties; a trait-resident memo would become
     * one independent cache per translatable model, each querying for the same global row.
     *
     * Nullable, not firstOrFail(): the read side must render an empty catalog on an unseeded
     * database (D-6c) -- the write side (App\Actions\ProductCategories\CreateProductCategory /
     * RenameProductCategory) fails loud instead.
     */
    private static ?self $defaultCache = null;

    /** Separate from the cache itself, so a "no default exists" answer is memoised too (D-10). */
    private static bool $defaultResolved = false;

    /**
     * Null-safe: returns null when no default row exists (a not-yet-seeded install) -- D-6.
     */
    public static function defaultStoreLanguage(): ?self
    {
        if (! self::$defaultResolved) {
            self::$defaultCache = static::query()->where('is_default', true)->first();
            self::$defaultResolved = true;
        }

        return self::$defaultCache;
    }

    /**
     * Flushed by this model's own `saved` hook (below) so a default change re-points the
     * fallback within the same request, and by `tests/Pest.php`'s `beforeEach` for every
     * Feature/Browser test, since RefreshDatabase's rollback fires no model event (R-6).
     */
    public static function flushDefaultStoreLanguage(): void
    {
        self::$defaultCache = null;
        self::$defaultResolved = false;
    }

    protected static function booted(): void
    {
        // App\Actions\StoreLanguages\{SetDefaultStoreLanguage,AddStoreLanguage,RemoveStoreLanguage}
        // all write through $model->save() (verified), so this re-points the fallback within the
        // same request without editing any 0068 action.
        static::saved(fn () => self::flushDefaultStoreLanguage());
    }

    /**
     * The bundled ISO 639-1 reference list, mapped `code => name_endonym` -- the single named
     * reader (D17) of `database/data/iso-639-languages.json`, shared by StoreLanguageSeeder and
     * StoreLanguageValidationRules::codeRules(). A shape guard throws on a missing or malformed
     * file, mirroring SalesRegionSeeder::assertValidCountryFixture() -- a fixture that silently
     * reads as empty would validate every submission against nothing.
     *
     * @return array<string, string>
     */
    public static function availableLanguages(): array
    {
        $path = database_path('data/iso-639-languages.json');

        throw_if(
            ! is_file($path),
            RuntimeException::class,
            "Missing ISO 639-1 language fixture at [{$path}].",
        );

        $contents = file_get_contents($path);

        throw_if(
            $contents === false,
            RuntimeException::class,
            "Unable to read the ISO 639-1 language fixture at [{$path}].",
        );

        $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertValidLanguageFixture($decoded, $path);

        /** @var array<int, array{code: string, name_endonym: string, name_en: string}> $decoded */
        $languages = [];

        foreach ($decoded as $entry) {
            $languages[$entry['code']] = $entry['name_endonym'];
        }

        return $languages;
    }

    /**
     * D8's advisory removal-warning extension point: how many rows across every registered
     * `config('store-languages.translation_relations')` entry reference the given store language.
     * Returns 0 today against the shipped-empty registry. Stories 0070+ complete it by appending
     * one `{table, column}` entry -- no edit to this method, the removal action, or any component.
     *
     * Takes a plain string id rather than a hydrated model, so a caller never needs to load the
     * row just to ask "is this one used anywhere".
     */
    public static function translationUsageCount(string $languageId): int
    {
        /** @var array<int, array{table: string, column: string}> $relations */
        $relations = config('store-languages.translation_relations', []);

        $count = 0;

        foreach ($relations as $relation) {
            $count += DB::table($relation['table'])->where($relation['column'], $languageId)->count();
        }

        return $count;
    }

    /**
     * Guard the decoded fixture's shape at runtime -- the `@var` annotation above is trusted by
     * Larastan but never checked when the JSON is actually loaded, so a malformed entry (missing
     * key, wrong type, uppercase code) would otherwise fail with an opaque error deep inside a
     * caller instead of a clear, actionable message. The code format check also rejects an
     * uppercase code, which would otherwise cause a case-insensitive collision later.
     */
    private static function assertValidLanguageFixture(mixed $decoded, string $path): void
    {
        throw_if(
            ! is_array($decoded) || ! array_is_list($decoded) || $decoded === [],
            RuntimeException::class,
            "The ISO 639-1 fixture at [{$path}] is not a non-empty JSON list.",
        );

        foreach ($decoded as $i => $entry) {
            throw_if(
                ! is_array($entry)
                || ! isset($entry['code'], $entry['name_endonym'], $entry['name_en'])
                || ! is_string($entry['code'])
                || preg_match('/^[a-z]{2}$/', $entry['code']) !== 1
                || ! is_string($entry['name_endonym']) || $entry['name_endonym'] === '',
                RuntimeException::class,
                "Malformed ISO 639-1 fixture entry at index [{$i}] in [{$path}].",
            );
        }
    }
}
