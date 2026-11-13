<?php

namespace Shirahcan\GrowthClient\Exceptions;

/**
 * growth-service could not be reached, timed out, answered 5xx or throttled (429).
 *
 * ⚠ FAIL CLOSED. There is no local copy to fall back on once a slice has moved: a product that kept
 * one "for outages" would be a second store of subscribers and consent, which is the drift the shared
 * service exists to end. Show the plain sentence; the fault is reported.
 */
class GrowthServiceUnavailable extends GrowthServiceException
{
    public function userMessage(): string
    {
        return 'Growth is briefly unavailable. Please try again in a minute.';
    }
}
