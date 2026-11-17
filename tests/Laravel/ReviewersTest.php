<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Testing\FakeReviewers;
use Shirahcan\GrowthClient\WebhookSignature;

/** B3: the product answers the service's signed "who may be named as a reviewer?" through its adapter. */
class ReviewersTest extends TestCase
{
    private const SECRET = 'c2VjcmV0LWZvci10ZXN0cy1vbmx5LTMyLWJ5dGVzISE='; // base64

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('growth-client.callback_secret', self::SECRET);
        $app['config']->set('growth-client.reviewers', FakeReviewers::class);
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::webhookRoute('/webhooks/growth-service');
    }

    private function ask(?string $signature = null)
    {
        $raw = '{}';
        $ts = (string) time();

        return $this->call('POST', '/webhooks/growth-service/reviewers', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_SIGNATURE' => $signature ?? WebhookSignature::sign($raw, $ts, base64_decode(self::SECRET)),
            'HTTP_X_GROWTH_TIMESTAMP' => $ts,
        ], $raw);
    }

    public function test_a_signed_call_gets_the_products_reviewers_and_its_freshness_window(): void
    {
        FakeReviewers::with([[
            'id' => 'c-1', 'legal_name' => 'Ada Okafor', 'credential' => 'RCIC', 'licence_number' => 'R123456',
            'licence_status' => 'Licensed', 'register_read_at' => '2026-11-16T05:00:00+00:00', 'opted_in_at' => '2026-11-17T00:00:00+00:00',
        ]], registerMaxAgeDays: 7);

        $this->ask()
            ->assertOk()
            ->assertJsonPath('data.reviewers.0.licence_number', 'R123456')
            ->assertJsonPath('data.reviewers.0.credential', 'RCIC')
            ->assertJsonPath('data.register_max_age_days', 7);
    }

    public function test_nobody_is_an_empty_list_not_an_error(): void
    {
        FakeReviewers::with([]);

        $this->ask()->assertOk()->assertJsonPath('data.reviewers', []);
    }

    public function test_unsigned_and_no_adapter(): void
    {
        $this->ask(signature: 'bm90LWEtc2lnbmF0dXJl')->assertStatus(401);

        config(['growth-client.reviewers' => null]);
        $this->ask()->assertStatus(503)->assertJsonPath('message', 'This product does not name any reviewers.');
    }

    public function test_the_reviewers_row_is_a_read_without_an_actor(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['GET', 'reviewers', 'api/v1/reviewers', false], $rows['GET reviewers']);
    }
}
