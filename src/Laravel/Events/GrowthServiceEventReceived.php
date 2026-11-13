<?php

namespace Shirahcan\GrowthClient\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Shirahcan\GrowthClient\Laravel\Models\GrowthServiceEvent;

/**
 * A new (never seen before) signed event from growth-service was recorded. Fired exactly once per
 * `event_id`. Products listen to act on it: an unsubscribe or a suppression made in the service (the
 * two-way rule while both copies exist, G4), an adapter callback.
 *
 * ⚠ Listen with a QUEUED listener: the service retries on a slow answer, so the receiver must answer
 * quickly. Call `$event->event->markProcessed()` once acted on.
 */
class GrowthServiceEventReceived
{
    use Dispatchable;

    public function __construct(public readonly GrowthServiceEvent $event) {}
}
