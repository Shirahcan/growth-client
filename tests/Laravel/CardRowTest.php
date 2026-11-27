<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;

/** G7e3: the share-card page reads a growth card through the public door, as a read carrying no visitor evidence. */
final class CardRowTest extends TestCase
{
    public function test_the_card_payload_is_a_public_read_with_no_evidence(): void
    {
        $rows = array_values(array_filter(GrowthRoutes::PUBLIC_TABLE, fn (array $r) => $r[1] === 'cards/{kind}/{uuid}'));

        $this->assertSame([['GET', 'cards/{kind}/{uuid}', 'api/v1/public/cards/{kind}/{uuid}', false]], $rows);
    }
}
