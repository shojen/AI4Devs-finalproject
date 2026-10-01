<?php

namespace Tests\Support\Dashboard;

use App\Models\User;
use Livewire\Features\SupportTesting\Testable;

/**
 * Shared fixtures and DOM probes for the story 0083 dashboard-home tests.
 *
 * A class with static methods rather than global Pest helpers (a global function declared by two
 * test files is a fatal redeclare -- see tests/Support/Orders/OrdersUi.php). Every probe works on a
 * RENDERED html string and addresses an element by its `data-test` hook, never by markup structure.
 */
final class DashboardUi
{
    /**
     * A NON-Super-Admin actor holding exactly the given permissions. Every "hidden" assertion must
     * use one of these: Gate::before grants a Super Admin every ability, so a hidden-module assertion
     * written with a Super Admin fixture is a guaranteed false negative.
     *
     * @param  array<int, string>  $permissions
     * @param  array<string, mixed>  $attributes  extra user columns (e.g. name, ui_locale)
     */
    public static function actor(array $permissions, array $attributes = []): User
    {
        $actor = User::factory()->create($attributes);

        if ($permissions !== []) {
            $actor->givePermissionTo($permissions);
        }

        return $actor;
    }

    public static function superAdmin(): User
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Admin');

        return $superAdmin;
    }

    /**
     * Every `wire:snapshot` attribute of the rendered component tree (the root first, then each
     * child), decoded to arrays. A page snapshot is only what the browser would receive, so this
     * is what must never carry a hidden module's data.
     *
     * @return list<array<string, mixed>>
     */
    public static function snapshotOf(Testable $component): array
    {
        preg_match_all('/wire:snapshot="([^"]*)"/', $component->html(), $matches);

        $snapshots = [];

        foreach ($matches[1] as $encoded) {
            $decoded = json_decode(html_entity_decode($encoded, ENT_QUOTES), true);

            if (is_array($decoded)) {
                $snapshots[] = $decoded;
            }
        }

        return $snapshots;
    }

    /**
     * The opening tag of the first element carrying the hook, or null when absent.
     */
    public static function tag(string $html, string $hook): ?string
    {
        if (! preg_match('/<[a-z][a-z0-9-]*\b[^>]*\bdata-test="'.preg_quote($hook, '/').'"[^>]*>/s', $html, $matches)) {
            return null;
        }

        return $matches[0];
    }

    public static function present(string $html, string $hook): bool
    {
        return self::tag($html, $hook) !== null;
    }

    /**
     * The tag name of the element carrying the hook (lower-case), or null when absent.
     */
    public static function tagName(string $html, string $hook): ?string
    {
        $tag = self::tag($html, $hook);

        if ($tag === null || ! preg_match('/^<([a-z][a-z0-9-]*)/i', $tag, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * The decoded, whitespace-collapsed text of a (leaf) element, or '' when the element is absent
     * (callers assert presence separately with present(); '' keeps a missing hook a clean assertion
     * failure instead of a type error inside a string expectation).
     */
    public static function text(string $html, string $hook): string
    {
        $pattern = '/<([a-z0-9-]+)\b[^>]*\bdata-test="'.preg_quote($hook, '/').'"[^>]*>(.*?)<\/\1>/is';

        if (preg_match($pattern, $html, $matches) !== 1) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($matches[2]), ENT_QUOTES)));
    }

    /**
     * An attribute of the element carrying the hook (decoded), or null when the element or the
     * attribute is absent.
     */
    public static function attribute(string $html, string $hook, string $attribute): ?string
    {
        $tag = self::tag($html, $hook);

        if ($tag === null || ! preg_match('/\s'.preg_quote($attribute, '/').'="([^"]*)"/', $tag, $matches)) {
            return null;
        }

        return html_entity_decode($matches[1], ENT_QUOTES);
    }

    /**
     * Present AND an anchor with an href.
     */
    public static function isLink(string $html, string $hook): bool
    {
        return self::tagName($html, $hook) === 'a' && self::attribute($html, $hook, 'href') !== null;
    }

    /**
     * The position of the hook in the document, for ordering assertions; false when absent.
     */
    public static function position(string $html, string $hook): int|false
    {
        return strpos($html, 'data-test="'.$hook.'"');
    }

    /**
     * Every data-test value in the html that starts with the given prefix.
     *
     * @return list<string>
     */
    public static function hooksStartingWith(string $html, string $prefix): array
    {
        preg_match_all('/\bdata-test="('.preg_quote($prefix, '/').'[^"]*)"/', $html, $matches);

        return array_values(array_unique($matches[1]));
    }
}
