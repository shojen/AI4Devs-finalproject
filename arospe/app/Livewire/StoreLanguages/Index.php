<?php

namespace App\Livewire\StoreLanguages;

use App\Models\StoreLanguage;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Backoffice Store Languages screen -- story 0068 ships the backend contract only (the catalog,
 * its actions, the bundled fixture); this is the component class and a resolving, permission-
 * gated route for sibling story 0069 to attach real markup to, matching what story 0017 shipped
 * for App\Livewire\SalesRegions\Index before story 0018 replaced its placeholder view.
 *
 * `viewAny` is authorized here in addition to the route's `can:store-languages.view` middleware
 * because Livewire's `/livewire/update` endpoint is a separate entry point that never runs route
 * middleware -- mounting the component directly (as every Livewire::test() call does) must be
 * denied on its own. Deliberately left unlogged, mirroring Users\Index::mount() /
 * SalesRegions\Index::mount(): a real HTTP actor who would fail this check is refused by the
 * route before ever reaching mount().
 */
#[Title('Store Languages')]
class Index extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', StoreLanguage::class);
    }
}
