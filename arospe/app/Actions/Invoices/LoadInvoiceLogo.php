<?php

namespace App\Actions\Invoices;

use App\Models\Media;
use finfo;
use Illuminate\Support\Facades\Storage;

/**
 * The logo of an issued invoice as a data URI (story 0102 FD-11), or null. Reads the ORIGINAL file
 * of the frozen media row from the public disk (never the webp/avif derivatives), refusing `..`
 * segments and absolute paths, and accepts only PNG, JPEG and WebP by sniffed mime, up to
 * `invoicing.pdf.logo_max_bytes`. Anything else renders the PDF without a logo.
 */
class LoadInvoiceLogo
{
    private const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    public function __invoke(?string $mediaId): ?string
    {
        if ($mediaId === null) {
            return null;
        }

        $path = Media::query()->whereKey($mediaId)->value('path');

        if (! is_string($path) || ! $this->isSafePath($path)) {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path) || $disk->size($path) > (int) config('invoicing.pdf.logo_max_bytes')) {
            return null;
        }

        $bytes = $disk->get($path);

        if (! is_string($bytes)) {
            return null;
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        if (! is_string($mime) || ! in_array($mime, self::ALLOWED_MIMES, true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }

    private function isSafePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '\\') || str_contains($path, "\0")) {
            return false;
        }

        return ! in_array('..', preg_split('#[/\\\\]#', $path) ?: [], true);
    }
}
