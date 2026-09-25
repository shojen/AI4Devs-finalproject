<?php

use App\Actions\Blog\BlogBodyHasVisibleContent;

// Story 0061b. The action is pure and judges an ALREADY-SANITIZED body, so this file feeds it markup the way
// the sanitizer would hand it over. What the sanitizer itself removes (style, script, comments) is covered
// end to end in the Feature tests, where the real allow-list runs.

test('a body that renders nothing has no visible content', function (?string $html) {
    expect((new BlogBodyHasVisibleContent)($html))->toBeFalse();
})->with([
    'null' => [null],
    'empty string' => [''],
    'plain whitespace' => ["  \n\t "],
    'paragraph with a line break' => ['<p><br></p>'],
    'bare line break' => ['<br>'],
    'several line breaks' => ['<br><br><br>'],
    'empty paragraph' => ['<p></p>'],
    'non-breaking space entity' => ['<p>&nbsp;</p>'],
    'non-breaking space character' => ["<p>\u{00A0}</p>"],
    'spaces only' => ['<p>   </p>'],
    'empty div' => ['<div style="display:none"></div>'],
    'empty list item' => ['<ul><li></li></ul>'],
    'empty heading' => ['<h2></h2>'],
    'empty link' => ['<a href="https://example.com"></a>'],
    'zero-width space entity' => ['<p>&#8203;</p>'],
    'zero-width space character' => ["<p>\u{200B}</p>"],
    'byte order mark entity' => ['<p>&#65279;</p>'],
    'zero-width joiner run' => ["<p>\u{200D}\u{200C}\u{2060}</p>"],
    'line separator' => ["<p>\u{2028}\u{2029}</p>"],
    'html comment' => ['<!-- just a comment -->'],
    'nested empties' => ['<ul><li><p></p></li><li><ol><li><br></li></ol></li></ul>'],
    'entity-encoded whitespace mix' => ['<p>&nbsp;&#32;&#9;&#8203;&nbsp;</p>'],
    'control characters' => ["<p>\u{0001}\u{0007}</p>"],
    'image without a src' => ['<img alt="Bota">'],
    'image with an empty src' => ['<img src="" alt="Bota">'],
    'image with a blank src' => ['<img src="   ">'],
    'invalid UTF-8' => ["<p>\xC3\x28</p>"],
]);

test('a body with visible text or an image has visible content', function (string $html) {
    expect((new BlogBodyHasVisibleContent)($html))->toBeTrue();
})->with([
    'plain text' => ['<p>Botas de invierno</p>'],
    'text without a wrapper' => ['Botas'],
    'a single character' => ['<p>a</p>'],
    'heading' => ['<h2>Título</h2>'],
    'list item' => ['<ul><li>Uno</li></ul>'],
    'link text' => ['<a href="https://example.com">enlace</a>'],
    'text between empty wrappers' => ['<p><br></p><p>hola</p><p></p>'],
    'text padded with non-breaking spaces' => ['<p>&nbsp;hola&nbsp;</p>'],
    'text after a comment' => ['<!-- nota --><p>hola</p>'],
    'symbol only' => ['<p>•</p>'],
    'dash only' => ['<p>—</p>'],
    'emoji only' => ['<p>👍</p>'],
    'accented text' => ['<p>Ñandú</p>'],
    'code block' => ['<pre><code>echo 1;</code></pre>'],
    'image only' => ['<img src="https://cdn.example.com/media/bota.jpg" alt="Bota">'],
    'image without alt' => ['<img src="https://cdn.example.com/media/bota.jpg">'],
    'image inside an empty paragraph' => ['<p><img src="https://cdn.example.com/media/bota.jpg"></p>'],
    'image next to an empty-src image' => ['<img src=""><img src="https://cdn.example.com/media/bota.jpg">'],
]);

test('the rule is any visible content, not only visible content', function () {
    expect((new BlogBodyHasVisibleContent)('<p><br></p><p>hola</p>'))->toBeTrue()
        ->and((new BlogBodyHasVisibleContent)('<p><br></p><p><br></p>'))->toBeFalse();
});

test('it is pure: the same input always gives the same answer', function () {
    $action = new BlogBodyHasVisibleContent;

    expect($action('<p>hola</p>'))->toBeTrue()
        ->and($action('<p><br></p>'))->toBeFalse()
        ->and($action('<p>hola</p>'))->toBeTrue();
});

test('a body at the sanitizer max_input_length is judged without error', function () {
    $action = new BlogBodyHasVisibleContent;
    $limit = 262_144;

    $emptyParagraphs = str_repeat('<p><br></p>', intdiv($limit, 11));
    $longText = '<p>'.str_repeat('a', $limit - 7).'</p>';
    $deepEmpties = str_repeat('<ul><li>', 200).str_repeat('</li></ul>', 200);

    expect(strlen($emptyParagraphs))->toBeLessThanOrEqual($limit)
        ->and($action($emptyParagraphs))->toBeFalse()
        ->and($action($longText))->toBeTrue()
        ->and($action($deepEmpties))->toBeFalse();
});
