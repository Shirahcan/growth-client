<?php

namespace Shirahcan\GrowthClient\Exceptions;

/**
 * 404: the record does not exist FOR THIS PRODUCT. The service answers a record of another product
 * with 404 too (F1 rule 5), so this never confirms that something exists elsewhere.
 */
class GrowthNotFound extends GrowthServiceException
{
    public function userMessage(): string
    {
        return 'That item could not be found. It may have been removed.';
    }

    public function shouldReport(): bool
    {
        return false;
    }
}
