<?php

namespace Shirahcan\GrowthClient;

use Shirahcan\GrowthClient\Exceptions\GrowthServiceException;

/**
 * What a product may ask growth-service. Typed calls are added slice by slice (G3 Plan/Radar, G4
 * audience, G5 campaigns, G6 results, G7 content); each lands here, in {@see GrowthServiceClient} and
 * in {@see Testing\FakeGrowth} in the same change, so a product's tests fail the way production would.
 *
 * ⚠ IT OWNS NO GROWTH DATA. No local cache of subscribers, consent or posts "for outages": a product
 * that kept one would be a second store, which is the drift the shared service exists to end. When
 * the service is down the call throws {@see Exceptions\GrowthServiceUnavailable}.
 *
 * Every method throws {@see GrowthServiceException} (or a subclass) on refusal or failure.
 */
interface GrowthClient
{
    /**
     * The product the SERVICE resolved from this product's key, and the acting product when the estate
     * key used {@see asProduct()}. Used by deploy checks and by a product to prove it is itself.
     *
     * @return array{product: string, acting_product: ?string}
     */
    public function whoami(): array;

    /**
     * A client that acts for another product with `?as_product=` (F1 rule 3). Only the estate key may;
     * a product key gets a {@see GrowthServiceException} with code `forbidden`. Returns a NEW client:
     * the original keeps acting for its own product.
     */
    public function asProduct(string $product): static;

    /**
     * The month's plan ideas (G3a), as growth-service's Plan answers it: `{month, plan_due, accepted,
     * ideas: [{uuid, title, audience, formats, why, status, skip_reason, planned_for, post_uuid}]}`.
     * Reads only: every plan write goes through the proxy, which records the actor.
     *
     * @param  string|null  $month  "YYYY-MM"; null = this month
     * @return array<string, mixed>
     */
    public function planIdeas(?string $month = null): array;

    /** @return array{signals: list<array<string, mixed>>} the open radar signals */
    public function planRadar(): array;

    /** @return array{sources: list<array<string, mixed>>} the product's watched sources */
    public function watchedSources(): array;
}
