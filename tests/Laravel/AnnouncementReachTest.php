<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Segments\AnnouncementReachAdapter;
use Shirahcan\GrowthClient\WebhookSignature;

/** G5c3a: the product counts how many of its own users an announcement reaches, signed, and never answers 0 for "unknown". */
class AnnouncementReachTest extends TestCase
{
    private const SECRET = 'c2VjcmV0LWZvci10ZXN0cy1vbmx5LTMyLWJ5dGVzISE='; // base64

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('growth-client.callback_secret', self::SECRET);
        $app['config']->set('growth-client.announcement_reach', ReachTestAdapter::class);
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::webhookRoute('/webhooks/growth-service');
    }

    private function ask(array $body, ?string $signature = null)
    {
        $raw = json_encode($body);
        $ts = (string) time();

        return $this->call('POST', '/webhooks/growth-service/announcements/reach', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_SIGNATURE' => $signature ?? WebhookSignature::sign($raw, $ts, base64_decode(self::SECRET)),
            'HTTP_X_GROWTH_TIMESTAMP' => $ts,
        ], $raw);
    }

    public function test_a_signed_call_counts_the_target_with_only_clean_picks(): void
    {
        $this->ask(['target' => ['mode' => 'all', 'include' => [], 'exclude' => []]])->assertOk()->assertJsonPath('data.count', 207);

        $this->ask(['target' => ['mode' => 'specific', 'include' => [['type' => 'segment', 'segment' => 'paid_clients', 'extra' => 'dropped'], ['type' => 'user', 'user_type' => 'admin', 'user_id' => 7]], 'exclude' => []]])
            ->assertOk()->assertJsonPath('data.count', 3);
        $this->assertSame([['type' => 'segment', 'segment' => 'paid_clients'], ['type' => 'user', 'user_type' => 'admin', 'user_id' => '7']], ReachTestAdapter::$last['include']);
    }

    public function test_unsigned_bad_targets_unavailable_and_no_adapter(): void
    {
        $this->ask(['target' => ['mode' => 'all']], 'bad')->assertStatus(401);
        $this->ask(['target' => ['mode' => 'everyone']])->assertStatus(422);
        $this->ask(['target' => ['mode' => 'specific', 'include' => [['type' => 'list', 'list' => 'x']]]])->assertStatus(422);

        ReachTestAdapter::$fail = true;
        $this->ask(['target' => ['mode' => 'all']])->assertStatus(503)->assertJsonPath('error', 'not_available');
        ReachTestAdapter::$fail = false;

        config()->set('growth-client.announcement_reach', null);
        $this->ask(['target' => ['mode' => 'all']])->assertStatus(503)->assertJsonPath('message', 'This product does not count who its announcements reach.');
    }
}

class ReachTestAdapter implements AnnouncementReachAdapter
{
    public static bool $fail = false;

    /** @var array<string, mixed> */
    public static array $last = [];

    public function count(array $target): int
    {
        if (self::$fail) {
            throw new FactsUnavailable('The users table could not be read.');
        }
        self::$last = $target;

        return $target['mode'] === 'all' ? 207 : 3;
    }
}
