<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;

/**
 * S4b (blog-on-growth-service): the proxy rows growth-ui's blog admin (S5) calls, mirroring growth-service's own
 * routes 1:1. Reads carry no actor; every change carries one, because the service records who made it.
 */
class BlogRowsTest extends TestCase
{
    public function test_the_category_rows_mirror_the_services_routes_with_the_actor_on_every_change(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['GET', 'blog/categories', 'api/v1/blog/categories', false], $rows['GET blog/categories']);
        $this->assertSame(['GET', 'blog/categories/{id}', 'api/v1/blog/categories/{id}', false], $rows['GET blog/categories/{id}']);
        $this->assertSame(['POST', 'blog/categories', 'api/v1/blog/categories', true], $rows['POST blog/categories']);
        $this->assertSame(['PUT', 'blog/categories/{id}', 'api/v1/blog/categories/{id}', true], $rows['PUT blog/categories/{id}']);
        $this->assertSame(['DELETE', 'blog/categories/{id}', 'api/v1/blog/categories/{id}', true], $rows['DELETE blog/categories/{id}']);
    }

    public function test_no_row_is_listed_twice(): void
    {
        $keys = array_map(fn (array $r) => $r[0].' '.$r[1], GrowthRoutes::TABLE);

        $this->assertSame(count($keys), count(array_unique($keys)));
    }
}
