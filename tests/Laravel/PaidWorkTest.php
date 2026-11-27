<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\Conversions\PaidWorkAdapter;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\WebhookSignature;

/** G6c2: the product says, signed, what its people went on to buy; unknown is "not available", never an empty map. */
class PaidWorkTest extends TestCase
{
    private const SECRET = 'c2VjcmV0LWZvci10ZXN0cy1vbmx5LTMyLWJ5dGVzISE=';

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('growth-client.callback_secret', self::SECRET);
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::webhookRoute('/webhooks/growth-service');
    }

    private function ask(array $body, ?string $signature = null)
    {
        $raw = json_encode($body);
        $ts = (string) time();

        return $this->call('POST', '/webhooks/growth-service/paid-work', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_SIGNATURE' => $signature ?? WebhookSignature::sign($raw, $ts, base64_decode(self::SECRET)),
            'HTTP_X_GROWTH_TIMESTAMP' => $ts,
        ], $raw);
    }

    private function bind(bool $fail = false): void
    {
        $this->app->instance(PaidWorkAdapter::class, new class($fail) implements PaidWorkAdapter {
            public function __construct(private readonly bool $fail) {}

            public function paidWorkFor(array $emails): array
            {
                if ($this->fail) {
                    throw new FactsUnavailable('Portify could not read its payments.');
                }

                return ['rita@example.com' => ['first_case_at' => '2026-11-20T10:00:00+00:00', 'cases' => 2, 'paid' => ['CAD' => 1250.5, 'cad' => 9, 'XX' => 1]], 'bad' => 'x'];
            }
        });
    }

    public function test_a_signed_call_answers_each_buyer_with_totals_per_currency_only(): void
    {
        $this->bind();
        $people = $this->ask(['emails' => ['Rita@Example.com', 'ben@example.com']])->assertOk()->json('data.people');
        $this->assertSame(['rita@example.com' => ['first_case_at' => '2026-11-20T10:00:00+00:00', 'cases' => 2, 'paid' => ['CAD' => 1250.5]]], $people, 'a malformed currency or row is dropped');
    }

    public function test_unsigned_malformed_too_many_unbound_and_unavailable(): void
    {
        $this->ask(['emails' => ['a@example.com']])->assertStatus(503);
        $this->bind();
        $this->ask(['emails' => ['a@example.com']], 'bad')->assertStatus(401);
        $this->ask(['emails' => 'a@example.com'])->assertStatus(422);
        $this->ask(['emails' => array_map(fn ($i) => "p{$i}@example.com", range(1, 501))])->assertStatus(422);
        $this->bind(fail: true);
        $this->ask(['emails' => ['a@example.com']])->assertStatus(503);
    }
}
