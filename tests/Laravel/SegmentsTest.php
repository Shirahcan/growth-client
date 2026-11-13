<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Segments\EmailHash;
use Shirahcan\GrowthClient\Segments\PlatformSegmentsAdapter;
use Shirahcan\GrowthClient\WebhookSignature;

/** G4d1: the product answers the service's signed questions about its own people, with data only. */
class SegmentsTest extends TestCase
{
    private const SECRET = 'c2VjcmV0LWZvci10ZXN0cy1vbmx5LTMyLWJ5dGVzISE='; // base64

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('growth-client.callback_secret', self::SECRET);
        $app['config']->set('growth-client.segments', SegmentsTestAdapter::class);
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::webhookRoute('/webhooks/growth-service');
    }

    private function ask(string $path, array $body, ?string $signature = null)
    {
        $raw = json_encode($body);
        $ts = (string) time();

        return $this->call('POST', '/webhooks/growth-service/segments'.$path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_SIGNATURE' => $signature ?? WebhookSignature::sign($raw, $ts, base64_decode(self::SECRET)),
            'HTTP_X_GROWTH_TIMESTAMP' => $ts,
        ], $raw);
    }

    public function test_a_signed_call_reads_the_catalogue_expands_picks_to_hashes_and_searches(): void
    {
        $this->ask('', [])->assertOk()->assertJsonPath('data.segments.0', ['key' => 'paid_clients', 'label' => 'Paid clients', 'kind' => 'client', 'count' => 2]);

        $this->ask('/expand', ['tokens' => [['type' => 'segment', 'segment' => 'paid_clients'], ['type' => 'user', 'user_type' => 'consultant', 'user_id' => 7]]])
            ->assertOk()
            ->assertJsonPath('data.hashes', [EmailHash::of('ana@example.com'), EmailHash::of('bo@example.com'), EmailHash::of('cy@example.com')])
            ->assertJsonPath('data.missing_email', 1);

        $this->ask('/search', ['q' => 'an', 'limit' => 99])->assertOk()->assertJsonPath('data.people.0.email_masked', 'a***@example.com')
            ->assertJsonPath('data.people.0.limit_seen', 25);
    }

    public function test_unsigned_bad_picks_unavailable_and_no_adapter(): void
    {
        $this->ask('', [], signature: 'bm90LWEtc2lnbmF0dXJl')->assertStatus(401);
        $this->ask('/expand', ['tokens' => [['type' => 'sql', 'q' => 'DROP']]], signature: 'bm90LWEtc2lnbmF0dXJl')->assertStatus(401); // the signature comes first
        $this->ask('/expand', ['tokens' => [['type' => 'sql', 'q' => 'DROP']]])->assertStatus(422);
        $this->ask('/expand', ['tokens' => 'all'])->assertStatus(422);
        $this->ask('/search', ['q' => 'a'])->assertStatus(422);
        $this->ask('/search', ['q' => 'down'])->assertStatus(503)->assertJsonPath('message', 'People are being re-indexed.');

        config(['growth-client.segments' => null]);
        $this->ask('', [])->assertStatus(503)->assertJsonPath('message', 'This product does not share its people groups.');
    }

    public function test_the_hash_rule_matches_the_service_and_the_mask_never_shows_the_whole_address(): void
    {
        $this->assertSame(hash('sha256', 'ana@example.com'), EmailHash::of('  Ana@Example.COM '));
        $this->assertSame('a***@example.com', EmailHash::mask('ana@example.com'));
        $this->assertNull(EmailHash::mask('not-an-address'));
    }
}

class SegmentsTestAdapter implements PlatformSegmentsAdapter
{
    public function catalog(): array
    {
        return [['key' => 'paid_clients', 'label' => 'Paid clients', 'kind' => 'client', 'count' => 2]];
    }

    public function expand(array $tokens): array
    {
        $emails = [];
        $missing = 0;
        foreach ($tokens as $t) {
            if ($t['type'] === 'segment' && $t['segment'] === 'paid_clients') {
                array_push($emails, 'ana@example.com', 'Bo@example.com ', 'ana@example.com');
            }
            if ($t['type'] === 'user' && $t['user_id'] === '7') {
                $emails[] = 'cy@example.com';
                $missing++;
            }
        }

        return ['hashes' => array_values(array_unique(array_map([EmailHash::class, 'of'], $emails))), 'missing_email' => $missing];
    }

    public function search(string $query, int $limit): array
    {
        if ($query === 'down') {
            throw new FactsUnavailable('People are being re-indexed.');
        }

        return [['user_type' => 'client', 'user_id' => '1', 'name' => 'Ana', 'email_masked' => EmailHash::mask('ana@example.com'), 'limit_seen' => $limit]];
    }
}
