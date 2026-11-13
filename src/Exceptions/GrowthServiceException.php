<?php

namespace Shirahcan\GrowthClient\Exceptions;

use RuntimeException;

/**
 * Anything growth-service refused or failed, with the two things a product needs (F1 rule 8):
 *
 * - {@see userMessage()}: a sentence safe to show the person. Never the transport's text.
 * - {@see shouldReport()}: true when this is a fault to `report()` (an outage, a 5xx, a deployment
 *   mistake such as a missing or revoked key), false when the service simply answered "no".
 *
 * The base class is what a product receives for an answer the kit did not expect: a 401 (this
 * product's key is missing, wrong or revoked) or a 403 (a product key named another product). Both are
 * deployment or programming faults, so both are reported.
 */
class GrowthServiceException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $errors  the service's per-field errors (422) or details
     */
    public function __construct(
        string $message,
        public readonly string $errorCode = 'unexpected',
        public readonly int $status = 0,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status);
    }

    public function userMessage(): string
    {
        return 'Growth could not complete that request. The problem has been logged.';
    }

    public function shouldReport(): bool
    {
        return true;
    }
}
