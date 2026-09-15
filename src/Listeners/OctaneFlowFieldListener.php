<?php

declare(strict_types=1);

namespace Schtzie\FlowField\Listeners;

use Schtzie\FlowField\Support\FlowFieldCache;
use Schtzie\FlowField\Support\FlowFieldQueryTracker;

/**
 * Octane compatibility listener for laravel-flowfield.
 *
 * In persistent worker environments (FrankenPHP, RoadRunner, Swoole),
 * static PHP properties survive across requests. This listener resets
 * all mutable per-request state on every Octane request boundary.
 *
 * Intentionally NOT reset: $flowFieldRegistry
 *   PHP attribute reflection is immutable — the definitions are the same
 *   for every request. Keeping the registry avoids reflection overhead.
 *
 * Compatible servers:
 *   - FrankenPHP  (via Laravel Octane)
 *   - RoadRunner  (via Laravel Octane)
 *   - Swoole      (via Laravel Octane)
 */
class OctaneFlowFieldListener
{
    /**
     * Handle Octane's RequestReceived event.
     * Runs at the start of every Octane request — resets stale state.
     */
    public function handleRequestReceived(mixed $event): void
    {
        $this->resetState();
    }

    /**
     * Handle Octane's RequestTerminated event.
     * Runs at the end of every Octane request — ensures clean shutdown.
     */
    public function handleRequestTerminated(mixed $event): void
    {
        $this->resetState();
    }

    /**
     * Handle Octane's TaskReceived event (Swoole task workers).
     */
    public function handleTaskReceived(mixed $event): void
    {
        $this->resetState();
    }

    /**
     * Handle Octane's TickReceived event (RoadRunner ticker).
     */
    public function handleTickReceived(mixed $event): void
    {
        FlowFieldQueryTracker::reset();
    }

    /**
     * Reset all mutable per-request static state.
     */
    protected function resetState(): void
    {
        if (config('flowfield.octane.reset_static_state', true)) {
            FlowFieldCache::resetStaticState();
        }
    }
}
