<?php

namespace Shirahcan\GrowthClient\Reviewers;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;

/**
 * Who may be named as a post's reviewer (post-and-newsletter-layouts B3): the product's own licensed people who
 * opted in. "Reviewed by <name>, RCIC <licence>" is an ATTESTATION, so only the product that holds the licence
 * record can say who qualifies, and it FAILS CLOSED: unverified, lapsed, stale or not opted in = not listed.
 * Listing someone is not a review; a post names a reviewer only after that person confirms its version (B4).
 *
 * The product binds one implementation (`growth-client.reviewers`); the kit serves it at
 * `POST {webhookRoute}/reviewers` (empty body).
 *
 * A reviewer:
 *   - id:                stable product id of the person (never an email)
 *   - legal_name:        the name as the licence register spells it
 *   - credential:        the designation the badge shows ("RCIC")
 *   - licence_number:    as the register holds it (e.g. "R706506")
 *   - licence_status:    the register's own word (only a licensed status is ever listed)
 *   - register_read_at:  ISO 8601, when the register last confirmed that status
 *   - opted_in_at:       ISO 8601, when the person agreed to review
 * Never contact details.
 */
interface ReviewersAdapter
{
    /**
     * @return array{
     *     reviewers: list<array{id: string, legal_name: string, credential: string, licence_number: string, licence_status: string, register_read_at: string, opted_in_at: string}>,
     *     register_max_age_days: int
     * }  `register_max_age_days`: a register read older than this is not trusted, so that person is left out
     *
     * @throws FactsUnavailable when the product cannot answer right now (a plain sentence)
     */
    public function reviewers(): array;
}
