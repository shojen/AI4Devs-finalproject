<?php

use App\Actions\Blog\FindOrCreateBlogTag;
use App\Actions\Blog\SyncBlogPostTags;
use App\Actions\Shipping\ResolveApplicableShippingRate;
use App\Actions\Translations\ResolveActiveStoreLanguage;
use Spatie\Permission\Models\Role;

// Story 0008 (re-audit F1): App\Models\Role is the only role model class application code may
// use -- a direct Spatie\Permission\Models\Role import reaches the same `roles` table but
// carries none of this story's guards (the deleting/updating/creating guards, the
// permission-pivot overrides, the selectable() scope), so it is a live bypass of every
// invariant this story builds, not a hypothetical one. Scoped to the two real PSR-4 roots this
// story's file list touches (composer.json has no bare `Database\` root) -- config/permission.php
// is outside both and is the one deliberate exception where the two classes are joined
// (`models.role` binding). App\Models\Role itself is `->ignoring()`'d: it legitimately extends
// the Spatie class, which arch() otherwise flags as "using" it.
//
// Two SEPARATE single-namespace rules, not `expect(['App', 'Database\Seeders'])`: Pest's
// `expect(array $targets)` evaluates disjunctively across the given targets -- it passes as soon
// as ANY ONE target satisfies the rule, even if another target in the same array violates it.
// A combined rule here would stay green even if `Database\Seeders` alone imported the raw
// Spatie model, as long as `App` didn't -- which is exactly how this test shipped vacuous the
// first time (verified: `expect(['App', 'Database\Seeders'])->not->toUse(Gate::class)` passes
// today even though Gate IS imported in app/Livewire/Users/Index.php).
arch('no application code imports the raw Spatie role model directly')
    ->expect('App')
    ->not->toUse(Role::class)
    ->ignoring('App\Models\Role');

arch('no seeder imports the raw Spatie role model directly')
    ->expect('Database\Seeders')
    ->not->toUse(Role::class);

// Story 0023, D-11: App\Models\ProductCategory must remain structurally independent from any
// future blog taxonomy -- own table, own model, own action namespace, own policy, no shared
// storage or identity, no polymorphic taxonomy. There is no blog taxonomy in code yet (PRD Epic 4
// -- Blog Categories/Tags/Posts do not exist), so this is honestly a SCOPE FENCE expressed as an
// executable test rather than a behavioral assertion today: it cannot fail yet, because there is
// nothing under a blog-taxonomy namespace for ProductCategory to import in the first place. It
// starts genuinely biting the moment Epic 4 lands a real blog taxonomy namespace under
// App\Models\Blog.
//
// A single-namespace `expect()` target, matching this file's own established one-rule-per-namespace
// convention (see the comment above the first two rules) -- not because this specific rule is at
// risk of the disjunctive-array-evaluation bug that convention exists to avoid (there is only one
// target here), but because every rule in this file follows the same shape for consistency.
arch('App\Models\ProductCategory does not reference any blog taxonomy namespace')
    ->expect('App\Models\ProductCategory')
    ->not->toUse('App\Models\Blog');

// Story 0024: App\Models\Product must remain structurally independent from any future blog
// taxonomy, matching App\Models\ProductCategory's identical fence directly above (0023, D-11).
// There is no blog taxonomy in code yet (PRD Epic 4 -- Blog Categories/Tags/Posts do not exist),
// so this is honestly a SCOPE FENCE expressed as an executable test rather than a behavioral
// assertion today: it cannot fail yet, because there is nothing under a blog-taxonomy namespace
// for Product to import in the first place. It starts genuinely biting the moment Epic 4 lands a
// real blog taxonomy namespace under App\Models\Blog.
//
// A single-namespace `expect()` target, matching this file's own established one-rule-per-
// namespace convention (see the comment above the first two rules) -- not because this specific
// rule is at risk of the disjunctive-array-evaluation bug that convention exists to avoid (there
// is only one target here), but for consistency with every other rule in this file.
arch('App\Models\Product does not reference any blog taxonomy namespace')
    ->expect('App\Models\Product')
    ->not->toUse('App\Models\Blog');

// Story 0025 (D-9): App\Livewire\ProductCategories\* must remain structurally independent from
// any future blog taxonomy, matching App\Models\ProductCategory's and App\Models\Product's
// identical fences directly above (0023 D-11, 0024). There is no blog taxonomy in code yet (PRD
// Epic 4 -- Blog Categories/Tags/Posts do not exist), so this is honestly a SCOPE FENCE expressed
// as an executable test rather than a behavioral assertion today: it cannot fail yet, because
// there is nothing under a blog-taxonomy namespace for the component to import in the first
// place. It starts genuinely biting the moment Epic 4 lands a real blog taxonomy namespace under
// App\Models\Blog.
//
// A single-namespace `expect()` target, per this file's own established one-rule-per-namespace
// convention (see the comment above the first two rules) and per this story's own note about a
// prior vacuous arch() rule (docs/errors-log.md): expect(array $targets) evaluates
// DISJUNCTIVELY, so `expect(['App\Models\ProductCategory', 'App\Livewire\ProductCategories'])`
// would stay green even if only one of the two namespaces violated it.
arch('App\Livewire\ProductCategories does not reference any blog taxonomy namespace')
    ->expect('App\Livewire\ProductCategories')
    ->not->toUse('App\Models\Blog');

// Story 0060 (OQ-5): App\Livewire\BlogTags\* must remain structurally independent from the product
// taxonomy -- the mirror of the fences above, pointed the other way. Blog tags are a standalone
// taxonomy (0059) and this screen must never borrow the product categories model or actions,
// whose delete semantics are the exact opposite (0060 D-2). One `expect()` per namespace, never
// `expect([...])`, which evaluates disjunctively (docs/errors-log.md's vacuous-arch()-rule entry).
arch('App\Livewire\BlogTags does not reference the product category model')
    ->expect('App\Livewire\BlogTags')
    ->not->toUse('App\Models\ProductCategory');

arch('App\Livewire\BlogTags does not reference the product category actions')
    ->expect('App\Livewire\BlogTags')
    ->not->toUse('App\Actions\ProductCategories');

// Story 0027 (Tests to perform, "tests/Unit/ArchitectureTest.php -- Modify -- extend the existing
// scope fence to cover App\Livewire\Products\*, matching 0025 D-9"): App\Livewire\Products\* must
// remain structurally independent from any future blog taxonomy, matching App\Models\ProductCategory's,
// App\Models\Product's and App\Livewire\ProductCategories's identical fences above. There is no blog
// taxonomy in code yet (PRD Epic 4 -- Blog Categories/Tags/Posts do not exist), so this is honestly
// a SCOPE FENCE expressed as an executable test rather than a behavioral assertion today: it cannot
// fail yet, because there is nothing under a blog-taxonomy namespace for the component to import in
// the first place. It starts genuinely biting the moment Epic 4 lands a real blog taxonomy namespace
// under App\Models\Blog.
//
// A single-namespace `expect()` target, per this file's own established one-rule-per-namespace
// convention and per the errors-log's own vacuous-arch()-rule entry: expect(array $targets)
// evaluates DISJUNCTIVELY, so a combined array target would stay green even if only one of several
// namespaces violated it.
arch('App\Livewire\Products does not reference any blog taxonomy namespace')
    ->expect('App\Livewire\Products')
    ->not->toUse('App\Models\Blog');

// Story 0029: App\Models\ProductVariant must remain structurally independent from any future blog
// taxonomy, matching every sibling Products-domain fence above (0023 D-11, 0024, 0025, 0027).
// There is no blog taxonomy in code yet (PRD Epic 4 -- Blog Categories/Tags/Posts do not exist), so
// this is honestly a SCOPE FENCE expressed as an executable test rather than a behavioral
// assertion today: it cannot fail yet, because there is nothing under a blog-taxonomy namespace for
// ProductVariant to import in the first place. It starts genuinely biting the moment Epic 4 lands a
// real blog taxonomy namespace under App\Models\Blog.
//
// A single-namespace `expect()` target, per this file's own established one-rule-per-namespace
// convention and per the story's own "one expect() per namespace, never expect([...])" note in its
// Files to create/modify table -- expect(array $targets) evaluates DISJUNCTIVELY, so a combined
// array target would stay green even if only one of several namespaces violated it
// (docs/errors-log.md's vacuous-arch()-rule entry).
arch('App\Models\ProductVariant does not reference any blog taxonomy namespace')
    ->expect('App\Models\ProductVariant')
    ->not->toUse('App\Models\Blog');

// Story 0037 (D-9): App\Livewire\Shipping\Index must never reference the rate-precedence
// resolver -- 0036 built it for PRD Epic 3's checkout consumption, and nothing on this admin
// screen quotes a rate for a destination. Normally this project rejects "assert the absence of a
// thing nobody proposed" tests (0034 D-1 rejects exactly that shape for its own screen); this one
// is the narrow, named exception, because ResolveApplicableShippingRate is a real,
// directly-importable class in the same namespace as every action this screen DOES call, and
// 0036's own D-13 invites "a future admin screen [to] surface coverage gaps from the same shape"
// -- a concrete, named temptation this line fences off.
arch('App\Livewire\Shipping\Index does not use the rate-precedence resolver')
    ->expect('App\Livewire\Shipping\Index')
    ->not->toUse(ResolveApplicableShippingRate::class);

// Story 0058 (D-11, OQ-2): the blog category taxonomy must stay structurally independent from the
// product category taxonomy -- own table, own model, own action namespace, own policy, no shared
// storage or identity, no polymorphic taxonomy. App\Models\ProductCategory exists in this tree, so
// the literal rule resolves and can genuinely go red (verified by temporarily importing it).
//
// A single-namespace `expect()` target, per this file's one-rule-per-namespace convention: Pest's
// expect(array $targets) evaluates DISJUNCTIVELY, so a combined array would pass as soon as one
// target satisfied it.
arch('App\Models\BlogCategory does not reference the product category taxonomy')
    ->expect('App\Models\BlogCategory')
    ->not->toUse('App\Models\ProductCategory');

// The other direction of the same fence. The product-taxonomy rules above name the namespace
// `App\Models\Blog`, but Pest matches a string target exactly, not by prefix, and the blog taxonomy
// lives in the flat class App\Models\BlogCategory -- so those rules cannot see it (verified by
// temporarily importing BlogCategory into ProductCategory: they stayed green). This one names the
// real class.
arch('App\Models\ProductCategory does not reference the blog category taxonomy')
    ->expect('App\Models\ProductCategory')
    ->not->toUse('App\Models\BlogCategory');

// Story 0062 (OQ-3): App\Livewire\BlogCategories\* must remain structurally independent from the
// product taxonomy. Unlike the fences above, this one asserts an absence that CAN be violated:
// `ProductCategory` and `BlogCategory` are four characters apart and both exist, so an IDE
// autocomplete accident is a real mechanism. One `expect()` per namespace, never `expect([...])`,
// which evaluates disjunctively (docs/errors-log.md's vacuous-arch()-rule entry).
arch('App\Livewire\BlogCategories does not reference the product category model')
    ->expect('App\Livewire\BlogCategories')
    ->not->toUse('App\Models\ProductCategory');

// Story 0063 (OQ-7): App\Livewire\BlogPosts\* reaches tags ONLY through the post actions.
// SyncBlogPostTags is a FULL-REPLACE sync() (0061 D-17) and FindOrCreateBlogTag asks a different
// ability per branch (0059 D-11); 0061's hand-off forbids any caller other than CreateBlogPost /
// UpdateBlogPost, because a component that called either directly would bypass the transaction
// that makes a refused new-tag name roll the whole save back. Unlike a scope fence, this asserts an
// absence that CAN be violated: both classes are one import away from the editor, and a "helpful"
// direct call is the exact temptation this line fences off. One `expect()` per rule and per
// namespace, never `expect([...])`, which evaluates disjunctively (docs/errors-log.md's
// vacuous-arch()-rule entry).
arch('App\Livewire\BlogPosts does not sync post tags directly')
    ->expect('App\Livewire\BlogPosts')
    ->not->toUse(SyncBlogPostTags::class);

arch('App\Livewire\BlogPosts does not resolve or mint tags directly')
    ->expect('App\Livewire\BlogPosts')
    ->not->toUse(FindOrCreateBlogTag::class);

// Story 0087 (D-6): every per-language translation WRITE action goes through the shared
// App\Actions\Translations\ResolveActiveStoreLanguage helper, so a store language that is no longer
// active can never be written into. Pest's arch() cannot select classes by filename pattern, so this
// is a plain test: it discovers every `app/Actions/**/Set*Translation.php` by glob (a sixth action is
// picked up automatically), except the unguarded SetTranslation primitive, then checks by reflection
// that the constructor takes the helper and that no inline `active()` re-read copy remains. A
// constructor parameter can still be injected and never called; the behavioural tests of each action
// close that gap.
//
// Discovery uses dirname(__DIR__) because tests/Unit has no TestCase, hence no base_path().
/**
 * @return array<string, class-string>
 */
function translationWriteActionClasses(): array
{
    $root = dirname(__DIR__, 2).'/app/Actions';
    $classes = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative = substr($file->getPathname(), strlen($root) + 1);

        if (! fnmatch('Set*Translation.php', $file->getFilename()) || $relative === 'Translations/SetTranslation.php') {
            continue;
        }

        /** @var class-string $class */
        $class = 'App\\Actions\\'.str_replace('/', '\\', substr($relative, 0, -4));
        $classes[$relative] = $class;
    }

    ksort($classes);

    return $classes;
}

test('the structural guard discovers the five current Set*Translation write actions', function () {
    expect(array_keys(translationWriteActionClasses()))->toBe([
        'Blog/SetBlogCategoryTranslation.php',
        'Blog/SetBlogPostTranslation.php',
        'Blog/SetBlogTagTranslation.php',
        'ProductCategories/SetProductCategoryTranslation.php',
        'Products/SetProductTranslation.php',
    ]);
});

test('every Set*Translation write action takes ResolveActiveStoreLanguage in its constructor', function () {
    $offenders = [];

    foreach (translationWriteActionClasses() as $class) {
        $parameterTypes = array_map(
            fn (ReflectionParameter $parameter): ?string => $parameter->getType() instanceof ReflectionNamedType ? $parameter->getType()->getName() : null,
            (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [],
        );

        if (! in_array(ResolveActiveStoreLanguage::class, $parameterTypes, true)) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([], 'These translation write actions do not inject '.ResolveActiveStoreLanguage::class.': '.implode(', ', $offenders));
});

test('no Set*Translation write action keeps an inline active store language re-read', function () {
    $offenders = [];

    foreach (translationWriteActionClasses() as $relative => $class) {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/app/Actions/'.$relative);

        if (preg_match('/StoreLanguage::query\(\)\s*->\s*active\(\)/', $source) === 1) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([], 'These translation write actions still inline the active() re-read instead of using the shared helper: '.implode(', ', $offenders));
});
