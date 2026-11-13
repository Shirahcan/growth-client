<?php

namespace Shirahcan\GrowthClient\Facts;

/**
 * What a product answers when growth-service asks for its current official figures (G3c). Radar reads
 * it to notice change (G3g) and post facts read it to check a quoted figure (G7a). The product binds
 * one implementation (`growth-client.facts`); the kit serves it at `POST {webhookRoute}/facts`.
 *
 * ⚠ NOT AVAILABLE IS AN ANSWER, A STALE DEFAULT IS NOT. When a figure cannot be read (the store was never
 * filled, the feed is unknown, a required scope is missing), throw FactsUnavailable with a plain
 * sentence. Never return an old value or an empty list to look healthy: Radar would read "nothing
 * changed" while the source is broken.
 *
 * A figure:
 *   - key:          stable id within the product (`ircc.processing_time.study.IN`, `round.345`)
 *   - label:        what a person calls it ("Study permit, India")
 *   - value:        the figure as people read it ("9 weeks", "CRS 518 on 2026-11-12")
 *   - number:       the comparable amount (days), or null when the figure has none
 *   - group:        what a change is grouped under on Radar ("Study permit"), or null
 *   - as_of:        the date the figure is true for (Y-m-d), or null when the source gives none
 *   - source_label, source_url: where a reader can check it themselves
 */
interface FactsAdapter
{
    /** Each figure is a happening (an Express Entry round): a key Radar has not seen is news. */
    public const KIND_EVENTS = 'events';

    /** Each figure is a level that moves (a processing time): a moved `number` is news. */
    public const KIND_LEVELS = 'levels';

    /**
     * The feeds this product answers for.
     *
     * @return list<array{feed: string, label: string, kind: string, source_label: string, source_url: string}>
     */
    public function feeds(): array;

    /**
     * Every current figure in one feed. `$scope` narrows a large feed (e.g. `countries`).
     *
     * @param  array<string, mixed>  $scope
     * @return list<array{key: string, label: string, value: string, number: int|float|null, group: string|null, as_of: string|null, source_label: string, source_url: string}>
     *
     * @throws FactsUnavailable
     */
    public function figures(string $feed, array $scope = []): array;

    /**
     * One figure by key, or null when the product has no such figure right now.
     *
     * @return array{key: string, label: string, value: string, number: int|float|null, group: string|null, as_of: string|null, source_label: string, source_url: string}|null
     *
     * @throws FactsUnavailable
     */
    public function figure(string $key): ?array;
}
