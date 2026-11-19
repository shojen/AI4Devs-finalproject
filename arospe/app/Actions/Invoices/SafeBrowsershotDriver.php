<?php

namespace App\Actions\Invoices;

use Closure;
use RuntimeException;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Drivers\BrowsershotDriver;
use Spatie\LaravelPdf\PdfOptions;

/**
 * Browsershot driver that loads the HTML from a private temp file instead of Browsershot::html()
 * (story 0102 Phase 4 M-1 / L-1). Browsershot's setHtml() string guard rejects any document that merely
 * contains `view-source`, `//localhost` or `//127.`, which would make an invoice with such free text
 * unrenderable. The file is created 0600 in a directory with no world bits and deleted in a `finally`.
 */
class SafeBrowsershotDriver extends BrowsershotDriver
{
    private ?string $htmlFilePath = null;

    /**
     * @throws RuntimeException
     */
    public function generatePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): string
    {
        try {
            return parent::generatePdf($html, $headerHtml, $footerHtml, $options);
        } finally {
            $this->removeHtmlFile();
        }
    }

    /**
     * @throws RuntimeException
     */
    public function savePdf(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options, string $path): void
    {
        try {
            parent::savePdf($html, $headerHtml, $footerHtml, $options, $path);
        } finally {
            $this->removeHtmlFile();
        }
    }

    /**
     * The parent loads the (empty) string and then runs the customise callback; the file is wired in
     * through a wrapper so it is already loaded when the callback runs.
     *
     * @throws RuntimeException
     */
    protected function buildBrowsershot(string $html, ?string $headerHtml, ?string $footerHtml, PdfOptions $options): Browsershot
    {
        $filePath = $this->writeHtmlFile($html);
        $original = $this->customizeBrowsershot;

        $this->customizeBrowsershot = function (Browsershot $browsershot) use ($filePath, $original): void {
            $browsershot->setHtmlFromFilePath($filePath);

            if ($original instanceof Closure) {
                $original($browsershot);
            }
        };

        try {
            return parent::buildBrowsershot('', $headerHtml, $footerHtml, $options);
        } finally {
            $this->customizeBrowsershot = $original;
        }
    }

    /**
     * @throws RuntimeException
     */
    private function writeHtmlFile(string $html): string
    {
        $directory = (string) ($this->config['temp_path'] ?? '');

        if ($directory === '') {
            $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'laravel-pdf-private';
        }

        if (! is_dir($directory)) {
            $previousUmask = umask(0077);

            try {
                if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                    throw new RuntimeException('Could not create the PDF temp directory.');
                }
            } finally {
                umask($previousUmask);
            }
        }

        $filePath = $directory.DIRECTORY_SEPARATOR.'invoice-'.bin2hex(random_bytes(16)).'.html';
        $previousUmask = umask(0077);

        try {
            $written = file_put_contents($filePath, $html);
        } finally {
            umask($previousUmask);
        }

        if ($written === false) {
            throw new RuntimeException('Could not write the PDF temp file.');
        }

        chmod($filePath, 0600);
        $this->htmlFilePath = $filePath;

        return $filePath;
    }

    private function removeHtmlFile(): void
    {
        if ($this->htmlFilePath !== null && file_exists($this->htmlFilePath)) {
            unlink($this->htmlFilePath);
        }

        $this->htmlFilePath = null;
    }
}
