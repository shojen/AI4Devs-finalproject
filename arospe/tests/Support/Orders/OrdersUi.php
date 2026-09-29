<?php

namespace Tests\Support\Orders;

use App\Models\User;

/**
 * Shared fixtures and DOM probes for the story 0055 orders-screen tests.
 *
 * A class with static methods rather than global Pest helpers: tests/Feature/Orders/ already holds
 * many files that declare global helper functions, and a redeclare is a fatal error. Every probe
 * works on a RENDERED html string, never on component state -- a hint can be right in the component
 * and wrong in the view.
 */
final class OrdersUi
{
    /** The four permission profiles the per-control dataset crosses (all additionally hold orders.view). */
    public const PROFILES = [
        'edit-only' => ['orders.view', 'orders.edit'],
        'refund-only' => ['orders.view', 'orders.refund'],
        'both' => ['orders.view', 'orders.edit', 'orders.refund'],
        'view-only' => ['orders.view'],
    ];

    /**
     * A NON-Super-Admin actor holding exactly the given permissions. Every disabled-state assertion
     * must use one of these: Gate::before grants a Super Admin every ability, so a disabled assertion
     * written with a Super Admin fixture is a guaranteed false negative.
     *
     * @param  array<int, string>  $permissions
     */
    public static function actor(array $permissions): User
    {
        $actor = User::factory()->create();

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
     * The opening tag of the first element carrying the given data-test hook, or null when absent.
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
     * Present AND disabled -- false when absent, so "absent" and "disabled" never read as the same thing.
     */
    public static function disabled(string $html, string $hook): bool
    {
        $tag = self::tag($html, $hook);

        return $tag !== null && (bool) preg_match('/\sdisabled(?=[\s=>\/])/', $tag);
    }

    public static function enabled(string $html, string $hook): bool
    {
        $tag = self::tag($html, $hook);

        return $tag !== null && ! preg_match('/\sdisabled(?=[\s=>\/])/', $tag);
    }

    /**
     * The html between the section's opening tag (identified by its data-test hook) and the next
     * section boundary -- approximated as the rest of the document up to the next top-level
     * data-test="*-section" hook, which is enough to scope an absence assertion to one section.
     */
    public static function section(string $html, string $hook): string
    {
        $start = strpos($html, 'data-test="'.$hook.'"');

        if ($start === false) {
            return '';
        }

        $rest = substr($html, $start + 1);

        if (preg_match('/data-test="[a-z-]+-section"/', $rest, $next, PREG_OFFSET_CAPTURE)) {
            return substr($rest, 0, $next[0][1]);
        }

        return $rest;
    }
}
