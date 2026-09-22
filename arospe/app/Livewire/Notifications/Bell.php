<?php

namespace App\Livewire\Notifications;

use Illuminate\Contracts\View\View;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Story 0057 -- the notification bell mounted from the shared layout
 * (resources/views/layouts/app/sidebar.blade.php, once, in the topbar.
 * Not a page: no route, no `can:` gate, no
 * config/modules.php entry (D-4) -- `auth`, already satisfied by the layout,
 * is the whole boundary, and every query below is scoped to Auth::user()'s
 * own morph key, so no id from the client ever reaches a query.
 *
 * Consumes story 0056's three call shapes verbatim, and nothing else reads
 * `notifications.type`: the per-type presentation branch lives in the Blade
 * view alone, with a permanent generic fallback (D-3).
 */
class Bell extends Component
{
    private const RECENT_LIMIT = 15;

    /** Whether the dropdown has been opened since mount; gates the list query. */
    #[Locked]
    public bool $opened = false;

    /**
     * Fired by the toggle. Mark-all-as-read runs on the loaded collection
     * (0056's call 3, the property); the count is then re-read in render()
     * through the METHOD, because markAsRead() leaves the stale collection
     * cached on the model.
     */
    public function open(): void
    {
        $this->opened = true;

        Auth::user()->unreadNotifications->markAsRead();
    }

    /**
     * 0056's call 1, the METHOD (one COUNT query). A computed rather than a
     * render() argument only because the count lives in its own Blade island
     * (D-2), which cannot see render()'s view data. It is first read during
     * render, after open()'s markAsRead(), so it is never stale within a request.
     */
    #[Computed]
    public function unreadCount(): int
    {
        return Auth::user()->unreadNotifications()->count();
    }

    /**
     * 0056's call 2. A computed so the query runs only if the view reads it
     * (inside `@if ($opened)`), never as a side effect of render() -- a poll
     * must not re-run the fifteen-row query (D-2).
     *
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function recentNotifications(): Collection
    {
        return Auth::user()->notifications()->latest()->limit(self::RECENT_LIMIT)->get();
    }

    public function render(): View
    {
        return view('livewire.notifications.bell');
    }
}
