<?php

// Story 0083, D-5 -- the two shared status-badge components extracted from the inline `match` blocks of
// orders.blade.php and blog-posts.blade.php. RED until resources/views/components/order-status-badge.blade.php
// and blog-status-badge.blade.php exist. Colours and labels are compared against what the lists render today
// (tests/Feature/Orders/OrdersListStatusBadgeTest.php, tests/Feature/Blog/BlogPostsIndexRenderingTest.php),
// so the extraction cannot drift from them.
//
// Decision on the unknown order status (the orders list's inline match has a `default => 'zinc'` arm, but
// OrderStatus::from() would throw a ValueError): the component must NOT throw. It renders a zinc badge whose
// label is the raw string, HTML-escaped. A dashboard row for a status added to the enum before the
// component's colour map is updated therefore degrades visibly instead of returning a 500.

use App\Enums\BlogPostStatus;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Blade;

/**
 * @return array{colour: ?string, label: string, open: string}|null
 */
function statusBadgeComponentsBadge(string $html): ?array
{
    if (! preg_match('/<div([^>]*\bdata-flux-badge\b[^>]*)>(.*?)<\/div>/is', $html, $matches)) {
        return null;
    }

    preg_match('/class="([^"]*)"/', $matches[1], $class);
    preg_match('/(?<![\w:-])text-([a-z]+)-\d{3}/', $class[1] ?? '', $colour);

    return [
        'colour' => $colour[1] ?? null,
        'label' => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($matches[2]), ENT_QUOTES))),
        'open' => '<div'.$matches[1].'>',
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function statusBadgeComponentsRender(string $template, array $data, string $locale = 'en'): string
{
    $previous = App::getLocale();
    App::setLocale($locale);

    try {
        return Blade::render($template, $data);
    } finally {
        App::setLocale($previous);
    }
}

// =====================================================================
// <x-order-status-badge> -- takes the status STRING value
// =====================================================================

test('the order status badge renders the orders list colour and English label for each status value', function (OrderStatus $status, string $colour, string $label) {
    $badge = statusBadgeComponentsBadge(statusBadgeComponentsRender(
        '<x-order-status-badge :status="$status" />',
        ['status' => $status->value],
    ));

    expect($badge)->not->toBeNull()
        ->and(['colour' => $badge['colour'], 'label' => $badge['label']])->toBe(['colour' => $colour, 'label' => $label]);
})->with([
    'pending is zinc' => [OrderStatus::Pending, 'zinc', 'Pending'],
    'processing is blue' => [OrderStatus::Processing, 'blue', 'Processing'],
    'shipped is amber' => [OrderStatus::Shipped, 'amber', 'Shipped'],
    'delivered is lime' => [OrderStatus::Delivered, 'lime', 'Delivered'],
    'cancelled is red' => [OrderStatus::Cancelled, 'red', 'Cancelled'],
]);

test('the order status badge speaks Spanish under the es locale', function (OrderStatus $status, string $label) {
    $badge = statusBadgeComponentsBadge(statusBadgeComponentsRender(
        '<x-order-status-badge :status="$status" />',
        ['status' => $status->value],
        'es',
    ));

    expect($badge['label'])->toBe($label);
})->with([
    'pending' => [OrderStatus::Pending, 'Pendiente'],
    'processing' => [OrderStatus::Processing, 'Procesando'],
    'shipped' => [OrderStatus::Shipped, 'Enviado'],
    'delivered' => [OrderStatus::Delivered, 'Entregado'],
    'cancelled' => [OrderStatus::Cancelled, 'Cancelado'],
]);

test('the order status badge forwards its attributes onto the badge element', function () {
    $badge = statusBadgeComponentsBadge(statusBadgeComponentsRender(
        '<x-order-status-badge :status="$status" data-test="dashboard-order-status-42" class="ms-2" />',
        ['status' => 'shipped'],
    ));

    expect($badge['open'])->toContain('data-test="dashboard-order-status-42"')
        ->and($badge['open'])->toContain('ms-2')
        // Forwarding must not cost the colour.
        ->and($badge['colour'])->toBe('amber');
});

test('an unknown order status string does not throw and renders a zinc badge showing the escaped raw value', function () {
    $html = statusBadgeComponentsRender(
        '<x-order-status-badge :status="$status" data-test="unknown-status" />',
        ['status' => 'on_hold<script>alert(1)</script>'],
    );

    $badge = statusBadgeComponentsBadge($html);

    expect($badge)->not->toBeNull()
        ->and($badge['colour'])->toBe('zinc')
        ->and($badge['label'])->toContain('on_hold')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($badge['open'])->toContain('data-test="unknown-status"');
});

// =====================================================================
// <x-blog-status-badge> -- takes the BlogPostStatus ENUM
// =====================================================================

test('the blog status badge renders the blog list colour and English label for each status', function (BlogPostStatus $status, string $colour, string $label) {
    $badge = statusBadgeComponentsBadge(statusBadgeComponentsRender(
        '<x-blog-status-badge :status="$status" />',
        ['status' => $status],
    ));

    expect($badge)->not->toBeNull()
        ->and(['colour' => $badge['colour'], 'label' => $badge['label']])->toBe(['colour' => $colour, 'label' => $label]);
})->with([
    'draft is zinc' => [BlogPostStatus::Draft, 'zinc', 'Draft'],
    'scheduled is amber' => [BlogPostStatus::Scheduled, 'amber', 'Scheduled'],
    'published is lime' => [BlogPostStatus::Published, 'lime', 'Published'],
]);

test('the blog status badge speaks Spanish under the es locale', function (BlogPostStatus $status, string $label) {
    $badge = statusBadgeComponentsBadge(statusBadgeComponentsRender(
        '<x-blog-status-badge :status="$status" />',
        ['status' => $status],
        'es',
    ));

    expect($badge['label'])->toBe($label);
})->with([
    'draft' => [BlogPostStatus::Draft, 'Borrador'],
    'scheduled' => [BlogPostStatus::Scheduled, 'Programado'],
    'published' => [BlogPostStatus::Published, 'Publicado'],
]);

test('the blog status badge forwards the row hook the blog list asserts on', function () {
    $badge = statusBadgeComponentsBadge(statusBadgeComponentsRender(
        '<x-blog-status-badge :status="$status" data-test="status-badge-blog-post-7" />',
        ['status' => BlogPostStatus::Published],
    ));

    expect($badge['open'])->toContain('data-test="status-badge-blog-post-7"')
        ->and($badge['colour'])->toBe('lime');
});
