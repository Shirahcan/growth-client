<?php

namespace Shirahcan\GrowthClient\Segments;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;

/**
 * The product's own people, as groups an admin can pick (G4d1). growth-service asks; the product answers
 * with DATA ONLY and never decides who may be mailed: the service applies consent and suppression.
 * The product binds one implementation (`growth-client.segments`); the kit serves it, signed, at
 * `POST {webhookRoute}/segments`, `/segments/expand` and `/segments/search`.
 *
 * A token names who was picked:
 *   - ['type' => 'segment', 'segment' => <key from catalog()>]
 *   - ['type' => 'user', 'user_type' => <kind>, 'user_id' => <id>]
 *
 * ⚠ HASHES, NEVER ADDRESSES. `expand()` answers sha256(lower(trim(email))) per person: the service only
 * matches its own subscribers, so no list of addresses crosses the wire. {@see EmailHash::of()} is the rule.
 */
interface PlatformSegmentsAdapter
{
    /**
     * The groups an admin can pick, in the product's own words.
     *
     * @return list<array{key: string, label: string, kind: string, count: int}>
     *
     * @throws FactsUnavailable
     */
    public function catalog(): array;

    /**
     * The people the tokens name, as email hashes, de-duplicated; `missing_email` counts people with no
     * address on file. An unknown segment key is ignored (the service sends only catalogue keys).
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return array{hashes: list<string>, missing_email: int}
     *
     * @throws FactsUnavailable
     */
    public function expand(array $tokens): array;

    /**
     * Named people for the picker: at most `$limit`, emails masked (the picker shows who, never the address).
     *
     * @return list<array{user_type: string, user_id: string, name: string, email_masked: string|null}>
     *
     * @throws FactsUnavailable
     */
    public function search(string $query, int $limit): array;
}
