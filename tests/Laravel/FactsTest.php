<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\Facts\FactsAdapter;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Laravel\Http\FactsController;
use Shirahcan\GrowthClient\WebhookSignature;

/** G3c: the product answers the service's signed "what is the figure now?" through its FactsAdapter. */
class FactsTest extends TestCase
{
    private const SECRET = 'c2VjcmV0LWZvci10ZXN0cy1vbmx5LTMyLWJ5dGVzISE='; // base64

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('growth-client.callback_secret', self::SECRET);
        $app['config']->set('growth-client.facts', FactsTestAdapter::class);
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::webhookRoute('/webhooks/growth-service');
    }

    private function ask(array $body, ?string $signature = null, ?int $timestamp = null)
    {
        $raw = json_encode($body);
        $ts = (string) ($timestamp ?? time());
        $sig = $signature ?? WebhookSignature::sign($raw, $ts, base64_decode(self::SECRET));

        return $this->call('POST', '/webhooks/growth-service/facts', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_SIGNATURE' => $sig,
            'HTTP_X_GROWTH_TIMESTAMP' => $ts,
        ], $raw);
    }

    public function test_a_signed_call_gets_the_feeds_and_the_figures_with_their_scope(): void
    {
        $this->ask(['op' => 'feeds'])->assertOk()->assertJsonPath('data.0.feed', 'demo.levels')->assertJsonPath('data.0.kind', FactsAdapter::KIND_LEVELS);

        $this->ask(['op' => 'figures', 'feed' => 'demo.levels', 'scope' => ['countries' => ['IN']]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.key', 'demo.IN')
            ->assertJsonPath('data.0.number', 63)
            ->assertJsonPath('data.0.as_of', '2026-11-10');

        $this->ask(['op' => 'figure', 'key' => 'demo.IN'])->assertOk()->assertJsonPath('data.value', '9 weeks');
        $this->ask(['op' => 'figure', 'key' => 'demo.ZZ'])->assertOk()->assertJsonPath('data', null);
    }

    public function test_the_events_route_is_still_where_it_was(): void
    {
        $this->assertSame('/webhooks/growth-service', parse_url(route('webhooks.growth-service'), PHP_URL_PATH));
        $this->assertSame('/webhooks/growth-service/facts', parse_url(route('webhooks.growth-service.facts'), PHP_URL_PATH));
    }

    public function test_not_available_is_an_answer_in_a_plain_sentence_never_an_empty_list(): void
    {
        $this->ask(['op' => 'figures', 'feed' => 'demo.never-filled'])
            ->assertStatus(503)
            ->assertJsonPath('error', 'not_available')
            ->assertJsonPath('message', 'The demo figures have not been loaded yet.');
    }

    public function test_a_product_with_no_adapter_says_so(): void
    {
        config(['growth-client.facts' => null]);

        $this->ask(['op' => 'feeds'])->assertStatus(503)->assertJsonPath('message', 'This product does not answer for any figures.');
    }

    public function test_a_configured_class_that_is_not_an_adapter_is_a_fault_not_a_silent_no(): void
    {
        config(['growth-client.facts' => \stdClass::class]);

        $this->ask(['op' => 'feeds'])->assertStatus(500);
    }

    public function test_a_bad_or_old_signature_is_refused(): void
    {
        $this->ask(['op' => 'feeds'], signature: 'bm90LWEtc2lnbmF0dXJl')->assertStatus(401);
        // Inside the receiver's 24 hours but outside a read's 5 minutes: a replay.
        $this->ask(['op' => 'feeds'], timestamp: time() - FactsController::TOLERANCE_SECONDS - 30)->assertStatus(401);

        config(['growth-client.callback_secret' => '']);
        $this->ask(['op' => 'feeds'])->assertStatus(401);
    }

    public function test_a_malformed_request_is_422(): void
    {
        $this->ask(['op' => 'delete'])->assertStatus(422);
        $this->ask(['op' => 'figures'])->assertStatus(422);
        $this->ask(['op' => 'figure', 'key' => '  '])->assertStatus(422);
    }

    public function test_a_fault_in_the_adapter_is_reported_and_answered_500_without_its_text(): void
    {
        $this->ask(['op' => 'figures', 'feed' => 'demo.broken'])
            ->assertStatus(500)
            ->assertJsonPath('message', 'The product could not read its figures.')
            ->assertJsonMissing(['message' => 'SQLSTATE secret detail']);
    }
}

class FactsTestAdapter implements FactsAdapter
{
    public function feeds(): array
    {
        return [['feed' => 'demo.levels', 'label' => 'Demo times', 'kind' => self::KIND_LEVELS, 'source_label' => 'Demo', 'source_url' => 'https://example.org/times']];
    }

    public function figures(string $feed, array $scope = []): array
    {
        return match ($feed) {
            'demo.levels' => array_values(array_filter($this->all(), fn ($f) => in_array(substr($f['key'], 5), (array) ($scope['countries'] ?? ['IN', 'NG']), true))),
            'demo.never-filled' => throw new FactsUnavailable('The demo figures have not been loaded yet.'),
            default => throw new \RuntimeException('SQLSTATE secret detail'),
        };
    }

    public function figure(string $key): ?array
    {
        return collect($this->all())->firstWhere('key', $key);
    }

    private function all(): array
    {
        return [
            ['key' => 'demo.IN', 'label' => 'Demo, India', 'value' => '9 weeks', 'number' => 63, 'group' => 'Demo', 'as_of' => '2026-11-10', 'source_label' => 'Demo', 'source_url' => 'https://example.org/times'],
            ['key' => 'demo.NG', 'label' => 'Demo, Nigeria', 'value' => '12 weeks', 'number' => 84, 'group' => 'Demo', 'as_of' => '2026-11-10', 'source_label' => 'Demo', 'source_url' => 'https://example.org/times'],
        ];
    }
}
