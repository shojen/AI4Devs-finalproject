<div>
    {{-- Placeholder view for App\Livewire\Shipping\Index — the real markup (carrier cards, the
    enable/disable toggle, the ACTIVO/INACTIVO badge) is the paired Epic 2 frontend story's. This
    story is backend-only and needs the component to mount and render without error, matching the
    identical placeholder story 0004 shipped for resources/views/livewire/users.blade.php. The
    app shell (sidebar/header) wraps this automatically, matching every other full-page Livewire
    component in resources/views/livewire/settings/**. --}}
    <p>{{ count($carriers) }}</p>
</div>
