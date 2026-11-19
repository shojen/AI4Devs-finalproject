<?php

// Story 0102 FD-4/FD-10 (T-12) -- the PDF template folder is static, escaped-only and loads nothing external.
// A headless browser renders these files, so the rules are enforced on the source, not only on sample output.

use App\Enums\InvoiceTemplate;

/**
 * @return list<string>
 */
function pdfTemplateFiles(): array
{
    $root = dirname(__DIR__, 2).'/resources/views/invoices/pdf';
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

test('the template folder holds both templates and the shared partials (T-12)', function () {
    $names = array_map(fn (string $file): string => basename($file, '.blade.php'), pdfTemplateFiles());

    expect($names)->toContain('classic', 'modern', 'head', 'header', 'parties', 'lines', 'totals', 'corrective-notice', 'copy-mark');
});

test('no template prints unescaped output, loads an external resource or runs script (T-12)', function (string $forbidden) {
    $offenders = collect(pdfTemplateFiles())
        ->filter(fn (string $file): bool => stripos((string) file_get_contents($file), $forbidden) !== false)
        ->map(fn (string $file): string => basename($file))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
})->with(['{!!', '<script', '<link', '@import', 'url(', 'http:', 'https:', 'file:']);

test('no template runs raw PHP, so no I/O can hide in a view (T-12)', function () {
    $offenders = collect(pdfTemplateFiles())
        ->filter(fn (string $file): bool => preg_match('/@php|<\?php|@include\s*\(\s*[\'"]?\s*\$|@inject|@use\b/i', (string) file_get_contents($file)) === 1)
        ->map(fn (string $file): string => basename($file))
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});

test('the head partial opens with the static Content-Security-Policy meta (T-12)', function () {
    $head = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/invoices/pdf/partials/head.blade.php');

    expect(ltrim($head))->toStartWith('<meta http-equiv="Content-Security-Policy"')
        ->and($head)->toContain("default-src 'none'; img-src data:; style-src 'unsafe-inline'");
});

test('both templates build on the same shared partials so the mandatory content cannot drift (FD-4)', function (InvoiceTemplate $template) {
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/invoices/pdf/'.$template->value.'.blade.php');

    foreach (['head', 'header', 'parties', 'lines', 'totals', 'corrective-notice', 'copy-mark'] as $partial) {
        expect($source)->toMatch('/@include\(\s*[\'"]invoices\.pdf\.partials\.'.preg_quote($partial, '/').'[\'"]/');
    }
})->with(InvoiceTemplate::cases());

test('every InvoiceTemplate case maps to an existing view named after it (FD-4)', function (InvoiceTemplate $template) {
    expect($template->view())->toBe('invoices.pdf.'.$template->value)
        ->and(is_file(dirname(__DIR__, 2).'/resources/views/invoices/pdf/'.$template->value.'.blade.php'))->toBeTrue();
})->with(InvoiceTemplate::cases());
