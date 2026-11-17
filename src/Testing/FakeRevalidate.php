<?php

namespace Shirahcan\GrowthClient\Testing;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Pages\RevalidateAdapter;

/**
 * A RevalidateAdapter for tests (S4a). Records every change it receives; tell it to report a failed bust
 * (`revalidated: false`) or to be not available, so a caller can test that a publish never waits on it.
 */
class FakeRevalidate implements RevalidateAdapter
{
    /** @var list<array<string, mixed>> */
    public static array $calls = [];

    private static ?string $failure = null;

    private static ?string $unavailable = null;

    public static function reset(?string $failure = null, ?string $unavailable = null): void
    {
        self::$calls = [];
        self::$failure = $failure;
        self::$unavailable = $unavailable;
    }

    public function revalidate(array $change): array
    {
        if (self::$unavailable !== null) {
            throw new FactsUnavailable(self::$unavailable);
        }
        self::$calls[] = $change;

        return self::$failure !== null
            ? ['revalidated' => false, 'detail' => self::$failure]
            : ['revalidated' => true, 'detail' => null];
    }
}
