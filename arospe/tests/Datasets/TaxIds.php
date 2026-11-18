<?php

// Stories 0100 (issuer tax ID) and 0100a (customer tax ID) share one format rule
// (App\Concerns\TaxIdValidationRules), so they share these datasets: the two stories cannot drift.

/*
 * Normalisation: [typed input, stored value]. All whitespace removed, ASCII-only upper-casing,
 * a blank becomes null; '.' and '/' are allowed characters, so '../x' is accepted.
 */
dataset('tax_id_normalisation', [
    'trim, case, keeps punctuation' => [' es-b12.34/5 ', 'ES-B12.34/5'],
    'inner space removed' => ['ES B123', 'ESB123'],
    'tab and newline removed' => ["ES\tB1\n23", 'ESB123'],
    'empty becomes null' => ['', null],
    'whitespace becomes null' => ['   ', null],
    'null stays null' => [null, null],
    'dot, slash and dash kept' => ['x.1/2-y', 'X.1/2-Y'],
    'newline inside removed' => ["B1\n2", 'B12'],
    'padded lower-case customer id' => [' b-12345678 ', 'B-12345678'],
    'spaced lower-case id' => ['es b 123', 'ESB123'],
    'dot-dot-slash is accepted' => ['../x', '../X'],
]);

/*
 * Invalid: each value contains a character outside A-Z, 0-9, '-', '.', '/' after normalisation.
 */
dataset('tax_id_invalid', [
    'underscore' => ['ES_B1'],
    'angle brackets' => ['<b>'],
    'semicolon inside' => ['ES;B1'],
    'dollar' => ['ES$B1'],
    'quote' => ["ES'B1"],
    'trailing semicolon' => ['B1;'],
    'sql comment' => ["B1'--"],
    'hash' => ['ES#1'],
    'emoji' => ["ES\u{1F600}1"],
    'n tilde' => ['ñ1'],
    'e acute' => ['é'],
    'invalid utf-8 bytes' => ["B1\xB1"],
]);
