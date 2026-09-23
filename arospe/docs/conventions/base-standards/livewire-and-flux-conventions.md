# Base Standards — Livewire, wire:ignore and Flux conventions

> Part of [Base Standards](../base-standards.md). **Read this part when:** you write a Livewire component, a `contenteditable`/browser-owned region, or a popover/dropdown. The other parts are listed in the [hub](../base-standards.md#parts).

## Livewire component convention: class-based, not single-file

Livewire 4 supports single-file components (PHP + Blade in one `.blade.php`), but this project consistently uses the **class-based / multi-file** form: a `Livewire\Component` subclass in `app/Livewire/**` paired with a same-named kebab-case view in `resources/views/livewire/**` (see [naming.md](../naming/livewire-components-and-views.md#livewire-components-and-views)):

```php
// app/Livewire/Settings/Appearance.php — minimal example of the pattern
namespace App\Livewire\Settings;

use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Appearance settings')]
class Appearance extends Component
{
    //
}
```

Every Livewire route in `routes/settings.php` is mounted with `Route::livewire('<uri>', <Component>::class)`, and every component declares its page `#[Title(...)]` attribute rather than setting the title from the Blade view. Follow this pattern for new settings/feature pages instead of introducing single-file components, to keep the codebase's one way of doing this.

## A `wire:ignore`d client-owned region — the app's first instance

Every Livewire component in this repo up to task 0021 lets Livewire own its whole rendered DOM: the server re-renders, Livewire morphs the difference into the page, and that is the entire lifecycle. [`App\Livewire\Components\WysiwygEditor`](../../../app/Livewire/Components/WysiwygEditor.php) is the first to need a **carve-out** from that, and it establishes the shape a later component follows if it ever needs the same thing.

The problem a plain Livewire re-render cannot survive is a browser `Selection`/caret inside a `contenteditable` region a user is actively typing into: a server round trip that morphs that subtree — even one that produces byte-identical HTML — destroys the caret position and can discard in-flight input. The fix is `wire:ignore` on the editable `<div>` itself:

```blade
{{-- resources/views/livewire/components/wysiwyg-editor.blade.php --}}
<div
    x-ref="editor"
    wire:ignore
    contenteditable="{{ $disabled ? 'false' : 'true' }}"
    role="textbox"
    aria-multiline="true"
    data-test="wysiwyg-editor-region"
    x-on:input="onEditorInput()"
    x-on:blur="onEditorBlur()"
>{!! $value !!}</div>
```

Three rules come with a `wire:ignore`d region like this one, and they generalise past this one component:

- **The region is seeded from server state exactly once, in the initial server-rendered HTML, never re-injected by JS.** `{!! $value !!}` runs once, on first mount — because the div is `wire:ignore`d, Livewire never touches it again, so a *later* server-side write to the bound property (a host resetting its form, say) intentionally does **not** reach the DOM. This is a documented consequence for any consumer to know, not a bug: a `wire:ignore`d region has no built-in "refresh me" mechanism, and one must be added explicitly (an Alpine method the host can call) if a future consumer needs programmatic content replacement.
- **The region syncs back to the server at defined points only, never continuously.** `wire:model.live` on every keystroke was considered and rejected: it would round-trip the whole value on every character and make the caret's survival depend on Livewire's morph running successfully on every keystroke — the exact risk `wire:ignore` exists to remove — for no benefit, since the value is only needed when the host form saves. `WysiwygEditor` instead debounces `$wire.set('value', editorEl.innerHTML)` 400 ms after `input`, plus one explicit call after a discrete action that does not reliably fire a native `input` event (an image insertion via `execCommand('insertHTML', ...)`).
- **`{!! !!}` unescaped output is safe here only because of what the value already is, not because the region is client-owned.** The seeded value is untrusted HTML by construction (a `#[Modelable]` property a consumer's `wire:model` writes through), and this component performs **no** sanitization of its own — it is rendered back out exactly as received. That is a *load-bearing dependency on the consumer*, not a property of `wire:ignore`: whatever persisted column ends up bound here must be sanitized server-side on its own write path before this component ever renders it, or the `{!! !!}` becomes a stored-XSS sink. See [api/products.md](../../api/products/routeless-components.md#applivewirecomponentswysiwygeditor--the-gallerys-first-real-consumer-and-the-second-routeless-gated-component) for which consumers already close this dependency and how.

**When to reach for this**: only for a region a browser API (here, `contenteditable`/`Selection`) actively owns and would fight a server re-render over. Do not reach for `wire:ignore` as a general performance shortcut — every other component in this app re-renders normally, and that remains the default.

## Flux Free's `ui-dropdown` requires a real `<button>` trigger descendant — confirmed twice, not a one-off

`flux:dropdown` (`ui-dropdown`) is this codebase's default open/close mechanism for a popover — it is what `resources/views/components/desktop-user-menu.blade.php` and `resources/views/layouts/app/sidebar.blade.php` already use, and both stories that needed a *new* popover assumed it would work the same way there. Neither could use it.

`ui-dropdown` resolves its trigger with a hard requirement, `this.querySelector("button")` (`vendor/livewire/flux/dist/flux.min.js`), and `ui-menu`'s own `boot()` unconditionally attaches a keydown listener to whatever that resolves to. A trigger element that renders no `<button>` descendant — `<flux:input>` renders only an `<input>` — makes `querySelector("button")` return `null`, and `w(null, "keydown", ...)` then throws `Cannot read properties of null (reading 'addEventListener')` on **every page load**, confirmed live via `assertNoJavaScriptErrors()` in both cases rather than assumed from reading the stub. `flux:dropdown` was never designed for "a text input triggers a live result list" — it is designed for "a button opens a static action menu" — and the trigger-resolution requirement is where that design assumption becomes a hard runtime failure rather than a styling mismatch.

The fallback is the same hand-assembled popover both stories independently reached for: an `x-data="{ open: false }"` wrapper, `x-show`/`x-cloak` on the popover body, `x-on:click.outside="open = false"`, and `x-on:keydown.escape.window` for dismissal — real Flux presentational subcomponents (`flux:menu.group`, `flux:menu.item`, and so on) kept for their styling, with only the outer `ui-dropdown`/`ui-menu` wrapper replaced. Story 0021's `wysiwyg-editor.blade.php` established this for its link-insertion popover; story 0022's `searchable-multi-select.blade.php` needed it again for its results dropdown, for the identical `querySelector("button")` reason, and its own file-banner comment documents the mechanism verified live rather than re-deriving it.

**The rule this confirms**: `flux:dropdown` is safe to reach for only when its trigger element genuinely renders a `<button>` — verify this by executing `assertNoJavaScriptErrors()` against the real page before trusting it, not by reading the Blade source, since the failure is entirely inside a vendored JS bundle no static read will show you. Any future popover trigger that is not itself a `<flux:button>`-rendering element (a `flux:input`, a custom trigger, a table cell) inherits the same constraint, and the manual `x-show`/`x-cloak`/`click.outside` shape above is the established fallback rather than something to reinvent per component.
