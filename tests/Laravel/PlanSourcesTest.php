<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Plan\PlanSourcesAdapter;
use Shirahcan\GrowthClient\WebhookSignature;

/** G3d3: the product answers the service's signed "what should we plan from?" through its adapter. */
class PlanSourcesTest extends TestCase
{
    private const SECRET = 'c2VjcmV0LWZvci10ZXN0cy1vbmx5LTMyLWJ5dGVzISE='; // base64

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('growth-client.callback_secret', self::SECRET);
        $app['config']->set('growth-client.plan_sources', PlanSourcesTestAdapter::class);
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::webhookRoute('/webhooks/growth-service');
    }

    private function ask(array $body, ?string $signature = null)
    {
        $raw = json_encode($body);
        $ts = (string) time();

        return $this->call('POST', '/webhooks/growth-service/plan-sources', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_SIGNATURE' => $signature ?? WebhookSignature::sign($raw, $ts, base64_decode(self::SECRET)),
            'HTTP_X_GROWTH_TIMESTAMP' => $ts,
        ], $raw);
    }

    public function test_a_signed_call_gets_the_products_sources_for_the_month(): void
    {
        $this->ask(['month' => '2026-11'])
            ->assertOk()
            ->assertJsonPath('data.sources.0.key', 'booked:study-guide:2026-11')
            ->assertJsonPath('data.published_titles.0', 'Study permits explained')
            ->assertJsonPath('data.gap_audiences.client', 'applicants')
            ->assertJsonPath('data.product.name', 'Demo');
    }

    public function test_unsigned_bad_month_unavailable_and_no_adapter(): void
    {
        $this->ask(['month' => '2026-11'], signature: 'bm90LWEtc2lnbmF0dXJl')->assertStatus(401);
        $this->ask(['month' => 'November'])->assertStatus(422);
        $this->ask(['month' => '1999-01'])->assertStatus(503)->assertJsonPath('message', 'Results are still being counted.');

        config(['growth-client.plan_sources' => null]);
        $this->ask(['month' => '2026-11'])->assertStatus(503)->assertJsonPath('message', 'This product does not share any plan sources.');
    }

    public function test_the_suggest_more_and_check_now_rows_are_whitelisted_with_the_actor(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['POST', 'plan/ideas/more', 'api/v1/plan/ideas/more', true], $rows['POST plan/ideas/more']);
        $this->assertSame(['POST', 'watched-sources/{id}/check', 'api/v1/watched-sources/{id}/check', true], $rows['POST watched-sources/{id}/check']);
    }
}

class PlanSourcesTestAdapter implements PlanSourcesAdapter
{
    public function planSources(string $month): array
    {
        if ($month === '1999-01') {
            throw new FactsUnavailable('Results are still being counted.');
        }

        return [
            'product' => ['name' => 'Demo', 'about' => 'A demo product.'],
            'sources' => [['key' => "booked:study-guide:{$month}", 'kind' => 'led_to_bookings', 'label' => 'Study guide', 'detail' => null, 'count' => 3, 'audience' => 'applicants', 'refs' => []]],
            'published_titles' => ['Study permits explained'],
            'gap_audiences' => ['client' => 'applicants'],
        ];
    }
}
