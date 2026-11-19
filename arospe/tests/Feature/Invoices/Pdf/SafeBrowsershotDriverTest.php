<?php

// Story 0102 Phase 4 M-1 / L-1 -- the app rebinds `laravel-pdf.driver.browsershot` to
// App\Actions\Invoices\SafeBrowsershotDriver, which loads the HTML from a private temp file so Browsershot's
// setHtml() string guard (`view-source`, `//localhost`, `//127.`) cannot make an invoice unrenderable.
// The real driver is exercised with a customise callback that captures the Browsershot and throws a sentinel
// before pdf() runs, so no Chromium starts. The package fake is still installed to satisfy the folder guard.

use App\Actions\Invoices\SafeBrowsershotDriver;
use Illuminate\Support\Facades\File;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\PdfOptions;
use Tests\Support\Invoices\FakesPdf;

beforeEach(function () {
    FakesPdf::fake();
    $this->tempPath = storage_path('framework/testing/pdf-temp-'.bin2hex(random_bytes(4)));
    config(['laravel-pdf.browsershot.temp_path' => $this->tempPath]);
    app()->forgetInstance('laravel-pdf.driver.browsershot');
});

afterEach(function () {
    File::deleteDirectory($this->tempPath);
});

/**
 * Run the driver up to (not including) the Chromium call and return what Browsershot was given.
 *
 * @return array{url: string, html: string, existedDuring: bool, contentDuring: string, mode: int}|array{}
 */
function captureSafeDriverBuild(string $html): array
{
    $captured = [];
    $driver = app('laravel-pdf.driver.browsershot');

    $driver->customizeBrowsershot(function (Browsershot $browsershot) use (&$captured): void {
        $url = (new ReflectionProperty(Browsershot::class, 'url'))->getValue($browsershot);
        $inlineHtml = (new ReflectionProperty(Browsershot::class, 'html'))->getValue($browsershot);
        $path = str_starts_with($url, 'file://') ? substr($url, 7) : '';
        $exists = $path !== '' && file_exists($path);

        $captured = [
            'url' => $url,
            'html' => $inlineHtml,
            'existedDuring' => $exists,
            'contentDuring' => $exists ? (string) file_get_contents($path) : '',
            'mode' => $exists ? fileperms($path) & 0777 : 0777,
        ];

        throw new RuntimeException('stop-before-chromium');
    });

    try {
        $driver->generatePdf($html, null, null, new PdfOptions);
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('stop-before-chromium');
    }

    return $captured;
}

test('the browsershot driver binding is the safe subclass (M-1)', function () {
    expect(app('laravel-pdf.driver.browsershot'))->toBeInstanceOf(SafeBrowsershotDriver::class);
});

test('html with a Browsershot-forbidden token is loaded from a private temp file instead of setHtml (M-1)', function (string $html) {
    $captured = captureSafeDriverBuild($html);

    expect($captured)->not->toBe([])
        ->and($captured['html'])->toBe('')
        ->and($captured['url'])->toStartWith('file://'.$this->tempPath)
        ->and($captured['existedDuring'])->toBeTrue()
        ->and($captured['contentDuring'])->toBe($html)
        ->and($captured['mode'] & 0077)->toBe(0);
})->with([
    'view-source in an address' => ['<p>Piso 2 view-source</p>'],
    'uppercase view-source' => ['<p>VIEW-SOURCE:x</p>'],
    '127.0.0.1 host' => ['<p>see //127.0.0.1/admin</p>'],
    'localhost host' => ['<p>see //localhost:8000/</p>'],
    'entity-encoded view-source' => ['<p>view&#45;source</p>'],
    'plain html' => ['<p>Calle Mayor 1</p>'],
]);

test('the temp file is removed afterwards even when the render fails (M-1)', function () {
    $captured = captureSafeDriverBuild('<p>view-source //127.0.0.1</p>');

    $leftover = is_dir($this->tempPath) ? File::allFiles($this->tempPath) : [];

    expect($captured)->not->toBe([])
        ->and($leftover)->toBe([]);
});

test('the temp directory is not world accessible (L-1)', function () {
    $captured = captureSafeDriverBuild('<p>Calle Mayor 1</p>');

    expect($captured)->not->toBe([])
        ->and(is_dir($this->tempPath))->toBeTrue()
        ->and(fileperms($this->tempPath) & 0007)->toBe(0);
});
