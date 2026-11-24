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

    public function test_the_post_rows_mirror_the_services_s2b_routes(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['GET', 'blog/posts', 'api/v1/blog/posts', false], $rows['GET blog/posts']);
        $this->assertSame(['POST', 'blog/posts', 'api/v1/blog/posts', true], $rows['POST blog/posts']);
        $this->assertSame(['POST', 'blog/posts/readiness', 'api/v1/blog/posts/readiness', false], $rows['POST blog/posts/readiness'], 'a check changes nothing');
        $this->assertSame(['POST', 'blog/posts/scores', 'api/v1/blog/posts/scores', false], $rows['POST blog/posts/scores'], 'a score changes nothing');
        $this->assertSame(['GET', 'blog/posts/{id}', 'api/v1/blog/posts/{id}', false], $rows['GET blog/posts/{id}']);
        $this->assertSame(['PUT', 'blog/posts/{id}', 'api/v1/blog/posts/{id}', true], $rows['PUT blog/posts/{id}']);
        $this->assertSame(['DELETE', 'blog/posts/{id}', 'api/v1/blog/posts/{id}', true], $rows['DELETE blog/posts/{id}']);
        $this->assertMatchesRegularExpression('/^'.GrowthRoutes::SEGMENT.'$/', '0198f2c4-5a1b-7c3d-9e8f-0123456789ab', 'a post uuid fits a row segment');
        $this->assertSame(['POST', 'blog/posts/{id}/publish', 'api/v1/blog/posts/{id}/publish', true], $rows['POST blog/posts/{id}/publish']);
        $this->assertSame(['POST', 'blog/posts/{id}/unpublish', 'api/v1/blog/posts/{id}/unpublish', true], $rows['POST blog/posts/{id}/unpublish']);
    }

    public function test_the_document_rows_mirror_the_services_s3c_routes(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        foreach ([
            ['GET', 'blog/posts/{id}/document', false],
            ['PUT', 'blog/posts/{id}/document', true],
            ['POST', 'blog/posts/{id}/document/preview', false],
            ['POST', 'blog/posts/{id}/document/check', false],
            ['POST', 'blog/posts/{id}/document/publish', true],
            ['DELETE', 'blog/posts/{id}/document/draft', true],
            ['GET', 'blog/posts/{id}/document/fields', false],
            ['PUT', 'blog/posts/{id}/document/fields/{name}', true],
        ] as [$method, $path, $actor]) {
            $this->assertSame([$method, $path, 'api/v1/'.$path, $actor], $rows[$method.' '.$path], $method.' '.$path);
        }
    }

    public function test_the_writing_rows_forward_the_actor_and_wait_for_the_model(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        foreach (['linkedin', 'proofread'] as $action) {
            $path = 'blog/posts/{id}/'.$action;
            $this->assertSame(['POST', $path, 'api/v1/'.$path, true, 90], $rows['POST '.$path], $action);
        }
    }

    public function test_the_convert_row_forwards_the_actor_with_the_default_timeout(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['POST', 'blog/posts/{id}/convert', 'api/v1/blog/posts/{id}/convert', true], $rows['POST blog/posts/{id}/convert']);
    }

    public function test_the_seo_panel_row_is_a_read_without_an_actor(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['GET', 'blog/posts/{id}/document/seo', 'api/v1/blog/posts/{id}/document/seo', false], $rows['GET blog/posts/{id}/document/seo']);
    }

    public function test_the_versions_rows_list_without_an_actor_and_restore_with_one(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['GET', 'blog/posts/{id}/versions', 'api/v1/blog/posts/{id}/versions', false], $rows['GET blog/posts/{id}/versions']);
        $this->assertSame(['POST', 'blog/posts/{id}/versions/{version}/restore', 'api/v1/blog/posts/{id}/versions/{version}/restore', true],
            $rows['POST blog/posts/{id}/versions/{version}/restore']);
        // A compare saves nothing: no actor.
        $this->assertSame(['POST', 'blog/posts/{id}/versions/{version}/compare', 'api/v1/blog/posts/{id}/versions/{version}/compare', false],
            $rows['POST blog/posts/{id}/versions/{version}/compare']);
    }

    public function test_preparing_a_post_has_no_browser_row(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertArrayNotHasKey('GET blog/posts/{id}/prepare', $rows->all(), 'the background prepare and its state are retired (0.27.0)');
        $this->assertArrayNotHasKey('POST blog/posts/{id}/prepare', $rows->all(), 'starting a prepare stays on the server of the product');
    }

    public function test_no_row_is_listed_twice(): void
    {
        $keys = array_map(fn (array $r) => $r[0].' '.$r[1], GrowthRoutes::TABLE);

        $this->assertSame(count($keys), count(array_unique($keys)));
    }
}
