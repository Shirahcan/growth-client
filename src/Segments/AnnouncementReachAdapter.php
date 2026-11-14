<?php

namespace Shirahcan\GrowthClient\Segments;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;

/**
 * How many of the product's own USERS an announcement would reach (G5c3a). An announcement is a service message shown
 * inside the product (banner, inbox) to people on the platform, not a newsletter to subscribers, so only the product
 * can count it. The product binds one implementation (`growth-client.announcement_reach`); the kit serves it, signed,
 * at `POST {webhookRoute}/announcements/reach`.
 *
 * A SEPARATE contract from {@see PlatformSegmentsAdapter} on purpose: adding a method to an interface a product already
 * implements would fatally break that product the moment the kit updates ahead of it. Unbound = 503 "not available",
 * never 0: a preview that says nobody when the truth is unknown is the silent-default defect.
 *
 * The target is the picker's: ['mode' => 'all'|'specific', 'include' => list<pick>, 'exclude' => list<pick>], each pick
 * a segment (['type' => 'segment', 'segment' => key]) or a user (['type' => 'user', 'user_type' => kind, 'user_id' => id]).
 */
interface AnnouncementReachAdapter
{
    /**
     * @param  array{mode: string, include: list<array<string, string>>, exclude: list<array<string, string>>}  $target
     *
     * @throws FactsUnavailable
     */
    public function count(array $target): int;
}
