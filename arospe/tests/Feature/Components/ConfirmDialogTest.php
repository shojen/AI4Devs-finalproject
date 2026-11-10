<?php

// Story 0093 -- optional second choice on the shared <x-confirm-dialog>. Render tests only; the
// interactive behaviour (Esc / backdrop / X abort, loading-disable) is covered through its first consumer.

use Illuminate\Support\Facades\Blade;

/**
 * @param  array<string, string>  $extra
 */
function confirmDialogHtml(array $extra = []): string
{
    $attributes = '';

    foreach (array_keys($extra) as $name) {
        $attributes .= ' :'.$name.'="$extra[\''.$name.'\']"';
    }

    return Blade::render(
        '<x-confirm-dialog :show="true" model="showDialog" heading="Heading" body="Body" confirm-label="Do it"
            dismiss-label="Go back" confirm-action="confirmIt" dismiss-action="dismissIt" test-prefix="demo"'.$attributes.' />',
        ['extra' => $extra],
    );
}

it('renders a dialog without a second choice as two buttons with no secondary hook', function (): void {
    $html = confirmDialogHtml();

    expect($html)
        ->toContain('data-test="confirm-dialog-demo-dismiss"')
        ->toContain('data-test="confirm-dialog-demo-confirm"')
        ->not->toContain('-secondary')
        ->not->toContain('flex-col-reverse');
});

it('renders three explicitly labelled options when both secondary props are set', function (): void {
    $html = confirmDialogHtml(['secondary-action' => 'secondIt', 'secondary-label' => 'Second choice']);

    expect($html)
        ->toContain('data-test="confirm-dialog-demo-secondary"')
        ->toContain('Second choice')
        ->toContain('wire:click="secondIt"')
        ->toContain('flex-col-reverse')
        ->toContain('data-test="confirm-dialog-demo-dismiss"')
        ->toContain('data-test="confirm-dialog-demo-confirm"');
});

it('does not render the secondary button when only one of the two props is set', function (string $name, string $value): void {
    $html = confirmDialogHtml([$name => $value]);

    expect($html)->not->toContain('-secondary')->not->toContain('flex-col-reverse');
})->with([
    'only the action' => ['secondary-action', 'secondIt'],
    'only the label' => ['secondary-label', 'Second choice'],
]);

it('escapes the secondary label', function (): void {
    $html = confirmDialogHtml(['secondary-action' => 'secondIt', 'secondary-label' => '<b>bold</b>']);

    expect($html)->toContain('&lt;b&gt;bold&lt;/b&gt;')->not->toContain('<b>bold</b>');
});

it('disables both action buttons while either action is loading', function (): void {
    $html = confirmDialogHtml(['secondary-action' => 'secondIt', 'secondary-label' => 'Second choice']);

    expect(substr_count($html, 'wire:loading.attr="disabled"'))->toBe(2)
        ->and(substr_count($html, 'wire:target="confirmIt,secondIt"'))->toBe(2);
});
