<?php

// Story 0102 FD-11 -- the logo is a server-built data URI from the frozen media row's original file (T-14, T-29).

use App\Actions\Invoices\LoadInvoiceLogo;
use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Invoices\FakesPdf;

/**
 * Real image bytes by format (the smallest valid files).
 */
function logoBytes(string $format): string
{
    return match ($format) {
        'png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true),
        'gif' => base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true),
        'jpeg' => base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=', true),
        'webp' => base64_decode('UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==', true),
        'svg' => '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>',
        'text' => "just text, not an image\n",
    };
}

/**
 * A media row whose original `path` holds the given bytes on the faked public disk.
 */
function logoMedia(string $bytes, string $path = 'media/logo.bin'): Media
{
    Storage::disk('public')->put($path, $bytes);

    return Media::factory()->create(['path' => $path, 'webp_path' => 'media/logo.webp', 'avif_path' => 'media/logo.avif']);
}

beforeEach(function () {
    FakesPdf::fake();
    Storage::fake('public');
    config(['invoicing.pdf.logo_max_bytes' => 100_000]);
});

// T-14
test('a PNG, JPEG or WebP original within the cap becomes a base64 data URI of its real mime (T-14)', function (string $format, string $mime) {
    $media = logoMedia(logoBytes($format), "media/logo.{$format}");

    expect(app(LoadInvoiceLogo::class)($media->id))
        ->toBe("data:{$mime};base64,".base64_encode(logoBytes($format)));
})->with([
    'png' => ['png', 'image/png'],
    'jpeg' => ['jpeg', 'image/jpeg'],
    'webp' => ['webp', 'image/webp'],
]);

test('the original file is read, never the webp or avif derivative (T-14)', function () {
    $media = logoMedia(logoBytes('png'), 'media/original.png');
    Storage::disk('public')->put('media/logo.webp', logoBytes('webp'));
    Storage::disk('public')->put('media/logo.avif', 'not-read');

    expect(app(LoadInvoiceLogo::class)($media->id))->toStartWith('data:image/png;base64,');
});

test('a disallowed or unreadable logo yields null and never throws (T-14)', function (string $case) {
    $mediaId = match ($case) {
        'svg' => logoMedia(logoBytes('svg'), 'media/logo.png')->id,
        'gif' => logoMedia(logoBytes('gif'), 'media/logo.png')->id,
        'text renamed as png' => logoMedia(logoBytes('text'), 'media/logo.png')->id,
        'oversize' => (function (): string {
            config(['invoicing.pdf.logo_max_bytes' => 10]);

            return logoMedia(logoBytes('png'), 'media/logo.png')->id;
        })(),
        'path traversal' => Media::factory()->create(['path' => '../../.env'])->id,
        'absolute path' => Media::factory()->create(['path' => '/etc/passwd'])->id,
        'missing file' => Media::factory()->create(['path' => 'media/gone.png'])->id,
        'unknown id' => '00000000-0000-7000-8000-000000000000',
        'null id' => null,
    };

    expect(app(LoadInvoiceLogo::class)($mediaId))->toBeNull();
})->with([
    'svg', 'gif', 'text renamed as png', 'oversize', 'path traversal', 'absolute path', 'missing file', 'unknown id', 'null id',
]);

test('a null media id runs no query at all', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    expect(app(LoadInvoiceLogo::class)(null))->toBeNull()
        ->and($queries)->toBe([]);
});
