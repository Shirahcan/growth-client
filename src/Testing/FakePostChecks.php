<?php

namespace Shirahcan\GrowthClient\Testing;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Posts\PostChecksAdapter;

/**
 * A PostChecksAdapter for tests (S4). Answers a fixed list (default: none) and records the posts it was asked about;
 * tell it to be not available so a caller can test that readiness never waits on the product.
 */
class FakePostChecks implements PostChecksAdapter
{
    /** @var list<array<string, mixed>> */
    public static array $calls = [];

    /** @var list<array<string, mixed>> */
    private static array $checks = [];

    private static ?string $unavailable = null;

    /** @param  list<array<string, mixed>>  $checks */
    public static function with(array $checks = [], ?string $unavailable = null): void
    {
        self::$calls = [];
        self::$checks = $checks;
        self::$unavailable = $unavailable;
    }

    public function checks(array $post): array
    {
        if (self::$unavailable !== null) {
            throw new FactsUnavailable(self::$unavailable);
        }
        self::$calls[] = $post;

        return self::$checks;
    }
}
