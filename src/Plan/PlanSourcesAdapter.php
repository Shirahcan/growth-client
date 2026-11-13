<?php

namespace Shirahcan\GrowthClient\Plan;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;

/**
 * What only the product knows that Growth plans from (G3d3): which topics led to customers, what people
 * keep asking it for, what it has already published, and how its assistant's audiences map to Growth's.
 * growth-service adds its own Radar signals and ai-service's unanswered questions, then writes ideas.
 * The product binds one implementation (`growth-client.plan_sources`); the kit serves it at
 * `POST {webhookRoute}/plan-sources` with body `{month: "YYYY-MM"}`.
 *
 * A source (the plan's facts, each a possible idea):
 *   - key:      stable across reads, unique per month where it is monthly ("booked:<slug>:2026-11")
 *   - kind:     led_to_bookings | people_asking (the service's own kinds are official_change and
 *               unanswered_question)
 *   - label, detail (nullable), count (nullable), audience (one of the product's Growth audiences), refs
 */
interface PlanSourcesAdapter
{
    /**
     * @return array{
     *     product: array{name: string, about: string},
     *     sources: list<array{key: string, kind: string, label: string, detail: string|null, count: int|null, audience: string, refs: array<string, mixed>}>,
     *     published_titles: list<string>,
     *     gap_audiences: array<string, string>
     * }  `gap_audiences` maps the assistant's audience names (client, employer...) to Growth audiences
     *
     * @throws FactsUnavailable when the product cannot answer right now (a plain sentence)
     */
    public function planSources(string $month): array;
}
