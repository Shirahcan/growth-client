<?php

namespace Shirahcan\GrowthClient\Exceptions;

/**
 * The service refused on the merits (422 validation, 409 a conflict or an invalid state). Fixing the
 * request helps; retrying does not. Its message is the service's own, written for the person (the
 * service's BaseController never returns exception text), so it is shown as is and not reported.
 */
class GrowthRequestRejected extends GrowthServiceException
{
    public function userMessage(): string
    {
        return $this->getMessage();
    }

    public function shouldReport(): bool
    {
        return false;
    }
}
