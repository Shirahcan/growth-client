<?php

namespace Shirahcan\GrowthClient\Conversions;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;

/**
 * When each address last became a customer of the product (G6a2): a case opened, a booking made, an order paid, in
 * the product's own sense. growth-service joins it to subscribers to say which posts' readers went on to convert.
 * The product binds one implementation (`growth-client.conversions`); the kit serves it, signed, at
 * `POST {webhookRoute}/conversions`.
 *
 * A SEPARATE contract on purpose: adding a method to an interface a product already implements would fatally break
 * that product the moment the kit updates ahead of it. Unbound = 503 "not available", never an empty map: a result
 * that says nobody converted while the truth is unknown is the silent-default defect.
 */
interface ConversionsAdapter
{
    /**
     * @param  list<string>  $emails  lower-cased, at most {@see \Shirahcan\GrowthClient\Laravel\Http\ConversionsController::MAX_EMAILS}
     * @return array<string, string> email => ISO-8601 time of the latest conversion; addresses that never converted are left out
     *
     * @throws FactsUnavailable
     */
    public function latestConversions(array $emails): array;
}
