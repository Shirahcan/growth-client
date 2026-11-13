<?php

namespace Shirahcan\GrowthClient\Exceptions;

/**
 * A request asked to act for another product (`?as_product=`), but this product holds no estate key.
 * Only the shirah.co portal does. Said plainly; not a fault to report.
 */
class EstateKeyNotConfigured extends GrowthServiceException
{
    public function __construct()
    {
        parent::__construct('This product does not hold the estate key, so it cannot act for another product.', 'estate_not_configured', 403);
    }

    public function userMessage(): string
    {
        return $this->getMessage();
    }

    public function shouldReport(): bool
    {
        return false;
    }
}
