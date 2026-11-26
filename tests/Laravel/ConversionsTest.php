<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\Conversions\ConversionsAdapter;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Testing\FakeGrowth;
use Shirahcan\GrowthClient\WebhookSignature;

/** G6a2: the product says, signed, when its people last converted; unknown is "not available", never an empty map. */
class ConversionsTest extends TestCase
{
    private const SECRET = 'c2VjcmV0LWZvci10ZXN0cy1vbmx5LTMyLWJ5dGVzISE='; // base64

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('growth-client.callback_secret', self::SECRET);
        $app['config']->set('growth-client.conversions', ConversionsTestAdapter::class);
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::webhookRoute('/webhooks/growth-service');
    }

    private function ask(array $body, ?string $signature = null)
    {
        $raw = json_encode($body);
        $ts = (string) time();

        return $this->call('POST', '/webhooks/growth-service/conversions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_SIGNATURE' => $signature ?? WebhookSignature::sign($raw, $ts, base64_decode(self::SECRET)),
            'HTTP_X_GROWTH_TIMESTAMP' => $ts,
        ], $raw);
    }

    public function test_a_signed_call_answers_each_address_lower_cased(): void
    {
        ConversionsTestAdapter::$fail = false;
        $this->ask(['emails' => ['Rita@Example.com', 'ben@example.com', 'rita@example.com']])->assertOk()
            ->assertJsonPath('data.latest', ['rita@example.com' => '2026-11-20T10:00:00+00:00']);
        $this->assertSame(['rita@example.com', 'ben@example.com'], ConversionsTestAdapter::$last, 'deduplicated after lower-casing');
    }

    public function test_unsigned_malformed_too_many_and_unavailable(): void
    {
        ConversionsTestAdapter::$fail = false;
        $this->ask(['emails' => ['a@example.com']], 'bad')->assertStatus(401);
        $this->ask(['emails' => 'a@example.com'])->assertStatus(422);
        $this->ask(['emails' => ['not-an-address']])->assertStatus(422);
        $this->ask(['emails' => array_map(fn ($i) => "p{$i}@example.com", range(1, 501))])->assertStatus(422);

        ConversionsTestAdapter::$fail = true;
        $this->ask(['emails' => ['a@example.com']])->assertStatus(503)->assertJsonPath('error', 'not_available');
    }

    public function test_an_unbound_product_is_not_available(): void
    {
        config(['growth-client.conversions' => null]);
        $this->ask(['emails' => ['a@example.com']])->assertStatus(503)->assertJsonPath('message', 'This product does not say when its people become customers.');
    }

    public function test_the_fake_records_views_and_answers_results(): void
    {
        $fake = (new FakeGrowth('portify'))->seedResults('portify', null, ['ee' => ['readers' => 3, 'subscribers' => 1]], 1);
        $this->assertSame(['received' => 2, 'added' => 1], $fake->recordViews([['page_ref' => 'a'], ['page_ref' => 'b']]));
        $this->assertSame(['ee' => ['readers' => 3, 'subscribers' => 1]], $fake->resultsForPosts(['ee', 'other']));
        $this->assertTrue($fake->results('month')['conversions_known']);
        $this->assertCount(1, $fake->calls('recordViews'));
    }
}

class ConversionsTestAdapter implements ConversionsAdapter
{
    public static bool $fail = false;

    /** @var list<string> */
    public static array $last = [];

    public function latestConversions(array $emails): array
    {
        if (self::$fail) {
            throw new FactsUnavailable('The cases table could not be read.');
        }
        self::$last = $emails;

        return ['Rita@example.com' => '2026-11-20T10:00:00+00:00', 'ben@example.com' => ''];
    }
}
