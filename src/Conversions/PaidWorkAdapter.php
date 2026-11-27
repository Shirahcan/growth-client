<?php

namespace Shirahcan\GrowthClient\Conversions;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;

/**
 * What each address went on to buy from the product (G6c2), so growth-service can credit the posts, pages and
 * newsletters that led to paid work: when their first case (booking, order) opened, how many, and what was paid,
 * per currency, in the product's own sense of "paid". Served, signed, at `POST {webhookRoute}/paid-work`.
 *
 * A SEPARATE contract from ConversionsAdapter on purpose (adding a method would break a product the moment the kit
 * updates ahead of it). Unbound = 503 "not available", never an empty map: "nobody paid" while the truth is unknown
 * is the silent-default defect. The service shows totals per item only, never a person's payment.
 */
interface PaidWorkAdapter
{
    /**
     * @param  list<string>  $emails  lower-cased, at most {@see \Shirahcan\GrowthClient\Laravel\Http\PaidWorkController::MAX_EMAILS}
     * @return array<string, array{first_case_at: string, cases: int, paid: array<string, float>}> email => their paid work; addresses with none are left out
     *
     * @throws FactsUnavailable
     */
    public function paidWorkFor(array $emails): array;
}
