{{--
    View for App\Livewire\PaymentMethods\Index (story 0038). Flat path, not
    payment-methods/index.blade.php -- the Index-in-a-subfolder exception,
    see docs/conventions/naming.md.

    Deliberate minimal placeholder: this story ships the backend only (list
    + edit-IBAN component, actions, policy, seeder), so Livewire::test() has
    something to render against. The real card/list + edit UI is the paired
    frontend story (0039), which consumes this component's public surface --
    mirroring the 0004 -> 0006 / 0017 -> 0018 / 0033 -> 0034 split already
    established for other module screens.
--}}
<div>
    <p>{{ count($paymentMethods) }}</p>
</div>
