<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\Laravel\Events\GrowthServiceEventReceived;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Laravel\Models\GrowthServiceEvent;
use Shirahcan\GrowthClient\WebhookSignature;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'c2VjcmV0LWZvci10ZXN0cy1vbmx5LTMyLWJ5dGVzISE='; // base64

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('growth-client.callback_secret', self::SECRET);
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::webhookRoute('/webhooks/growth-service');
    }

    private function deliver(array $body, ?string $signature = null, ?int $timestamp = null)
    {
        $raw = json_encode($body);
        $ts = (string) ($timestamp ?? time());
        $sig = $signature ?? WebhookSignature::sign($raw, $ts, base64_decode(self::SECRET));

        return $this->call('POST', '/webhooks/growth-service', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_SIGNATURE' => $sig,
            'HTTP_X_GROWTH_TIMESTAMP' => $ts,
        ], $raw);
    }

    public function test_a_signed_event_is_recorded_and_announced_once_even_when_redelivered(): void
    {
        Event::fake([GrowthServiceEventReceived::class]);
        $body = ['event_id' => 'evt_1', 'event' => 'subscriber.unsubscribed', 'email' => 'ada@example.com'];

        $this->deliver($body)->assertOk();
        $this->deliver($body)->assertOk();

        $this->assertSame(1, GrowthServiceEvent::count());
        Event::assertDispatchedTimes(GrowthServiceEventReceived::class, 1);
    }

    public function test_a_bad_signature_is_refused_for_good(): void
    {
        $this->deliver(['event_id' => 'evt_2', 'event' => 'x'], signature: 'bm90LWEtc2lnbmF0dXJl')->assertStatus(401);
        $this->assertSame(0, GrowthServiceEvent::count());
    }

    public function test_a_stale_delivery_is_refused_as_a_replay(): void
    {
        $this->deliver(['event_id' => 'evt_3', 'event' => 'x'], timestamp: time() - WebhookSignature::TOLERANCE_SECONDS - 60)->assertStatus(401);
    }

    public function test_with_no_secret_configured_everything_is_refused(): void
    {
        config(['growth-client.callback_secret' => '']);

        $this->deliver(['event_id' => 'evt_4', 'event' => 'x'])->assertStatus(401);
    }

    public function test_a_body_without_its_identity_is_malformed(): void
    {
        $this->deliver(['event' => 'x'])->assertStatus(422);
    }

    public function test_once_acted_on_only_the_events_identity_is_kept(): void
    {
        $this->deliver(['event_id' => 'evt_5', 'event' => 'subscriber.unsubscribed', 'email' => 'ada@example.com'])->assertOk();

        $row = GrowthServiceEvent::sole();
        $row->markProcessed();

        $this->assertSame(['event' => 'subscriber.unsubscribed', 'event_id' => 'evt_5'], $row->fresh()->payload);
    }
}
