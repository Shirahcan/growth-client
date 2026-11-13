<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;

/** G4e4a: every Audience action is a whitelisted row, and every write records who decided. */
class AudienceRowsTest extends TestCase
{
    public function test_audience_reads_carry_no_actor_and_every_write_does(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->filter(fn (array $r) => str_starts_with($r[1], 'audience/') || $r[1] === 'settings/audience');

        $this->assertCount(25, $rows);
        foreach ($rows as [$method, $path, $upstream, $actor]) {
            $this->assertSame('api/v1/'.$path, $upstream, "{$method} {$path} maps to its own service path");
            $isRead = $method === 'GET' || in_array($path, ['audience/estimate', 'audience/imports/preview'], true);
            $this->assertSame(! $isRead, $actor, "{$method} {$path}: ".($isRead ? 'a read never claims an actor' : 'a write records who decided'));
        }
    }

    public function test_the_public_sign_up_is_still_not_a_row(): void
    {
        $this->assertFalse(collect(GrowthRoutes::TABLE)->contains(fn (array $r) => $r[1] === 'audience/subscribe'));
        $this->assertFalse(collect(GrowthRoutes::PUBLIC_TABLE)->contains(fn (array $r) => $r[1] === 'audience/subscribe'));
    }
}
