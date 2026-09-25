<?php

namespace App\Actions\Blog;

use DOMDocument;
use DOMElement;

/**
 * Whether a blog post body, ALREADY sanitized, shows a reader anything (story 0061b, D-1).
 *
 * "No body" is judged on what a reader would see, not on the string: `<p><br></p>`, `<p>&nbsp;</p>`,
 * an empty list or an empty link are all non-blank text that survives the sanitizer, yet render as
 * an empty page. A body has visible content when it holds at least one visible character or one
 * `<img>` with a non-empty `src`. Whitespace, separators and format characters (`&nbsp;`, zero-width
 * spaces, the byte order mark) are not visible; a bullet or a dash is.
 *
 * Pure and side-effect free, and only ever handed the sanitized value (D-3): the sanitizer is what
 * removes `style`, `hidden`, `<script>` and comments, so this rule needs no knowledge of how content
 * can be hidden. The parse is offline (`LIBXML_NONET`, no `LIBXML_NOENT`), so no entity or DTD is
 * ever fetched or expanded, and it is linear in the input, which the sanitizer already caps at
 * `html-sanitizer.max_input_length`. Invalid UTF-8 or an unparseable body counts as no content.
 */
class BlogBodyHasVisibleContent
{
    /** Every character a reader cannot see: whitespace, separators, format and control characters. */
    private const INVISIBLE = '/[\s\p{Z}\p{Cf}\p{Cc}]+/u';

    public function __invoke(?string $sanitizedHtml): bool
    {
        if ($sanitizedHtml === null || $sanitizedHtml === '' || ! mb_check_encoding($sanitizedHtml, 'UTF-8')) {
            return false;
        }

        $root = $this->parse($sanitizedHtml);

        if ($root === null) {
            return false;
        }

        foreach ($root->getElementsByTagName('img') as $image) {
            if ($this->isVisible($image->getAttribute('src'))) {
                return true;
            }
        }

        return $this->isVisible($root->textContent);
    }

    /**
     * The document element of the parsed fragment, or null when libxml cannot build one.
     */
    private function parse(string $html): ?DOMElement
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            // The XML declaration is what makes libxml read the fragment as UTF-8 rather than Latin-1.
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $loaded ? $document->documentElement : null;
    }

    private function isVisible(string $text): bool
    {
        $visible = preg_replace(self::INVISIBLE, '', $text);

        return $visible !== null && $visible !== '';
    }
}
