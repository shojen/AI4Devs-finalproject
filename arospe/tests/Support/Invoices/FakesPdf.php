<?php

namespace Tests\Support\Invoices;

use PHPUnit\Framework\Assert;
use ReflectionProperty;
use Spatie\LaravelPdf\Facades\Pdf;
use Spatie\LaravelPdf\FakePdfBuilder;
use Spatie\LaravelPdf\PdfBuilder;

/**
 * Story 0102 -- every test under tests/Feature/Invoices/Pdf swaps the PDF engine for the package's
 * fake, so no test starts Chromium by accident. The only exception is the opt-in smoke test (T-30),
 * which calls `optOut()` explicitly. `tests/Pest.php` resets the flag before and asserts it after
 * every test of that folder.
 */
final class FakesPdf
{
    private static bool $used = false;

    private static bool $optedOut = false;

    /**
     * Swap the PDF engine for the package fake and remember that this test did.
     */
    public static function fake(): FakePdfBuilder
    {
        self::$used = true;

        return Pdf::fake();
    }

    /**
     * Declare that this test renders with the real engine (the opt-in smoke only).
     */
    public static function optOut(): void
    {
        self::$optedOut = true;
    }

    public static function reset(): void
    {
        self::$used = false;
        self::$optedOut = false;
    }

    /**
     * The guard: fails a test of the PDF folder that neither faked the engine nor opted out.
     */
    public static function assertUsed(): void
    {
        Assert::assertTrue(
            self::$used || self::$optedOut,
            'A test under tests/Feature/Invoices/Pdf must call FakesPdf::fake() (or FakesPdf::optOut() in the opt-in smoke).',
        );
    }

    /**
     * The PDF builder the last HTTP response was produced from (the fake records a clone on toResponse()).
     */
    public static function lastResponded(FakePdfBuilder $fake): PdfBuilder
    {
        $responded = (new ReflectionProperty(FakePdfBuilder::class, 'respondedWithPdf'))->getValue($fake);

        Assert::assertNotEmpty($responded, 'No PDF was returned as a response.');

        return end($responded);
    }

    /**
     * How many PDFs were returned as responses.
     */
    public static function respondedCount(FakePdfBuilder $fake): int
    {
        return count((new ReflectionProperty(FakePdfBuilder::class, 'respondedWithPdf'))->getValue($fake));
    }

    /**
     * The HTML the last responded PDF would have been rendered from.
     */
    public static function respondedHtml(FakePdfBuilder $fake): string
    {
        $pdf = self::lastResponded($fake);

        return view($pdf->viewName, $pdf->viewData)->render();
    }
}
