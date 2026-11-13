<?php

namespace Shirahcan\GrowthClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\GrowthClient\GrowthServiceClient;
use Shirahcan\GrowthClient\Testing\FakeGrowth;

/** G3a: the typed Plan reads, on the real client and on the fake. */
class PlanReadsTest extends TestCase
{
    public function test_the_client_reads_ideas_radar_and_sources_from_the_house_envelope(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['success' => true, 'data' => ['month' => '2026-11', 'plan_due' => '2026-11-20', 'accepted' => 1, 'ideas' => [['uuid' => 'a']]]])),
            new Response(200, [], json_encode(['success' => true, 'data' => ['signals' => [['id' => 3]]]])),
            new Response(200, [], json_encode(['success' => true, 'data' => ['sources' => [['id' => 9]]]])),
        ]));
        $stack->push(Middleware::history($history));
        $client = new GrowthServiceClient('http://x', 'k', 5, new Guzzle(['handler' => $stack, 'base_uri' => 'http://x/']));

        $this->assertSame('a', $client->planIdeas('2026-11')['ideas'][0]['uuid']);
        $this->assertSame([['id' => 3]], $client->planRadar()['signals']);
        $this->assertSame([['id' => 9]], $client->watchedSources()['sources']);
        $this->assertSame('month=2026-11', $history[0]['request']->getUri()->getQuery());
        $this->assertSame('/api/v1/watched-sources', $history[2]['request']->getUri()->getPath());
    }

    public function test_the_fake_answers_only_its_own_products_rows(): void
    {
        $fake = FakeGrowth::estate('portify')
            ->seedPlan('portify', ideas: [['uuid' => 'p1', 'status' => 'accepted']], sources: [['id' => 1]])
            ->seedPlan('mploynow', ideas: [['uuid' => 'm1', 'status' => 'idea']]);

        $this->assertSame(['p1'], array_column($fake->planIdeas()['ideas'], 'uuid'));
        $this->assertSame(1, $fake->planIdeas()['accepted']);
        $this->assertSame(['m1'], array_column($fake->asProduct('mploynow')->planIdeas()['ideas'], 'uuid'));
        $this->assertSame([], $fake->asProduct('mploynow')->watchedSources()['sources']);
        $this->assertSame([], FakeGrowth::forProduct('mploynow')->planRadar()['signals'], 'nothing seeded = nothing, never another product\'s rows');
    }
}
