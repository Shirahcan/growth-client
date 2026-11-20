<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;

/**
 * Growth templates (owner 2026-11-20): the template rows mirror growth-service's B1 to B4 routes 1:1. Reads carry no
 * actor; every change carries one, because the service records who made it.
 */
class TemplateRowsTest extends TestCase
{
    public function test_the_template_rows_mirror_the_services_routes(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['POST', 'layouts', 'api/v1/layouts', true], $rows['POST layouts']);
        $this->assertSame(['POST', 'layouts/{key}/retire', 'api/v1/layouts/{key}/retire', true], $rows['POST layouts/{key}/retire']);
        $this->assertSame(['POST', 'layouts/{key}/restore', 'api/v1/layouts/{key}/restore', true], $rows['POST layouts/{key}/restore']);
        $this->assertSame(['POST', 'layouts/from-post/{post}', 'api/v1/layouts/from-post/{post}', true], $rows['POST layouts/from-post/{post}']);
        $this->assertSame(['POST', 'layouts/from-campaign/{campaign}', 'api/v1/layouts/from-campaign/{campaign}', true], $rows['POST layouts/from-campaign/{campaign}']);
        $this->assertSame(['GET', 'layouts/{key}/document', 'api/v1/layouts/{key}/document', false], $rows['GET layouts/{key}/document']);
        $this->assertSame(['PUT', 'layouts/{key}/document', 'api/v1/layouts/{key}/document', true], $rows['PUT layouts/{key}/document']);
        $this->assertSame(['GET', 'layouts/{key}/reader-preview', 'api/v1/layouts/{key}/reader-preview', false], $rows['GET layouts/{key}/reader-preview']);
        // E3: the draft, its checks and canvas, "Save for new posts", and the details.
        $this->assertSame(['POST', 'layouts/{key}/document/preview', 'api/v1/layouts/{key}/document/preview', false], $rows['POST layouts/{key}/document/preview']);
        $this->assertSame(['POST', 'layouts/{key}/document/check', 'api/v1/layouts/{key}/document/check', false], $rows['POST layouts/{key}/document/check']);
        $this->assertSame(['POST', 'layouts/{key}/document/publish', 'api/v1/layouts/{key}/document/publish', true], $rows['POST layouts/{key}/document/publish']);
        $this->assertSame(['DELETE', 'layouts/{key}/document/draft', 'api/v1/layouts/{key}/document/draft', true], $rows['DELETE layouts/{key}/document/draft']);
        $this->assertSame(['GET', 'layouts/{key}/document/fields', 'api/v1/layouts/{key}/document/fields', false], $rows['GET layouts/{key}/document/fields']);
        $this->assertSame(['PUT', 'layouts/{key}/document/fields/{name}', 'api/v1/layouts/{key}/document/fields/{name}', true], $rows['PUT layouts/{key}/document/fields/{name}']);
    }
}
