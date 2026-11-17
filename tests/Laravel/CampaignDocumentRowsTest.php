<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use PHPUnit\Framework\TestCase;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;

/**
 * N1d (blog-on-growth-service): a designed newsletter's rows, mirroring growth-service's N1b routes 1:1. Reads and
 * previews carry no actor; a save carries one, because the service records who made it.
 */
class CampaignDocumentRowsTest extends TestCase
{
    public function test_the_newsletter_document_rows_mirror_the_services_routes(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['GET', 'campaigns/{uuid}/document', 'api/v1/campaigns/{uuid}/document', false], $rows['GET campaigns/{uuid}/document']);
        $this->assertSame(['PUT', 'campaigns/{uuid}/document', 'api/v1/campaigns/{uuid}/document', true], $rows['PUT campaigns/{uuid}/document']);
        $this->assertSame(['POST', 'campaigns/{uuid}/document/preview', 'api/v1/campaigns/{uuid}/document/preview', false], $rows['POST campaigns/{uuid}/document/preview'], 'a preview changes nothing');
        $this->assertSame(['POST', 'campaigns/{uuid}/document/check', 'api/v1/campaigns/{uuid}/document/check', false], $rows['POST campaigns/{uuid}/document/check']);
        $this->assertSame(['GET', 'campaigns/{uuid}/document/fields', 'api/v1/campaigns/{uuid}/document/fields', false], $rows['GET campaigns/{uuid}/document/fields']);
        $this->assertSame(['PUT', 'campaigns/{uuid}/document/fields/{name}', 'api/v1/campaigns/{uuid}/document/fields/{name}', true], $rows['PUT campaigns/{uuid}/document/fields/{name}']);
    }

    public function test_preparing_an_issue_waits_longer_than_its_writer_and_records_who_asked(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['POST', 'campaigns/{uuid}/prepare', 'api/v1/campaigns/{uuid}/prepare', true, 120], $rows['POST campaigns/{uuid}/prepare'], 'above growth-service\'s 100 s wait on the writer');
    }

    public function test_a_campaign_starts_designed_through_the_create_row_it_already_has(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['POST', 'campaigns', 'api/v1/campaigns', true], $rows['POST campaigns'], 'layout_key rides the body of the create');
        $this->assertArrayNotHasKey('DELETE campaigns/{uuid}/document/draft', $rows->all(), 'a newsletter has no published-vs-draft split to throw away');
    }
}
