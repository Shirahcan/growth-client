<?php

namespace Shirahcan\GrowthClient\Laravel\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * A signed event growth-service delivered to this product, one row per `event_id`.
 *
 * ⚠ THE INBOX IS NOT A COPY OF ANYONE'S DATA. An event may carry an email address (a suppression, an
 * unsubscribe) so the product can act on it. Once acted on, {@see markProcessed()} keeps only what
 * deduplication and an audit need, and rows go after RETENTION_DAYS (Prunable). Same lesson as
 * calendar-client 0.33.0, where every snapshot stayed for ever, outside every erasure.
 */
class GrowthServiceEvent extends Model
{
    use Prunable;

    public const RETENTION_DAYS = 30;

    protected $table = 'growth_service_events';

    protected $fillable = ['event_id', 'event_type', 'payload', 'processed_at'];

    protected $casts = ['payload' => 'array', 'processed_at' => 'datetime'];

    /** Acted on: keep the event's identity only. */
    public function markProcessed(): void
    {
        $this->forceFill([
            'processed_at' => now(),
            'payload' => ['event' => $this->event_type, 'event_id' => $this->event_id],
        ])->save();
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
