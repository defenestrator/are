<?php

namespace App\Livewire\Overlays;

use App\Enums\Overlay;
use App\Enums\OverlayLayout;
use App\Models\OverlayToken;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

/**
 * Base for the overlay Volt components that follow the question queue
 * (queue, vote, top-vote).
 *
 * They update over the public `questions` channel in the browser
 * (resources/js/live-overlay.js, #22). Their server renders come from
 * $refresh: event-driven refreshes, the polling fallback while the socket is
 * down, and a 60-90 s heartbeat while it is up. Those requests go to
 * /livewire/update, so EnsureOverlayToken never sees them, and Livewire's
 * persistent middleware replays the route path without its query string. So
 * the component captures the token's hash when the page loads and checks that
 * it is still current on every render. Once the token is rotated, the overlay
 * renders empty with data-live="off", and the browser unsubscribes and stops
 * refreshing. That is what viewers should see, rather than an error dialog
 * appearing on stream.
 */
abstract class PollingOverlay extends Component
{
    #[Locked]
    public ?string $tokenHash = null;

    #[Locked]
    public string $layout = OverlayLayout::Horizontal->value;

    abstract protected function overlay(): Overlay;

    public function mount(): void
    {
        // The page request has just passed EnsureOverlayToken, so the current
        // hash is the one belonging to the token in the URL.
        $this->tokenHash = OverlayToken::currentHash($this->overlay());
        $this->layout = OverlayLayout::fromQuery($this->layout)->value;
    }

    public function tokenIsCurrent(): bool
    {
        return OverlayToken::hashIsCurrent($this->overlay(), $this->tokenHash);
    }

    public function isVertical(): bool
    {
        return OverlayLayout::fromQuery($this->layout) === OverlayLayout::Vertical;
    }
}
