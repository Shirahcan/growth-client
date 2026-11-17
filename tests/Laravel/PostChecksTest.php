<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Testing\FakePostChecks;
use Shirahcan\GrowthClient\WebhookSignature;

/** S4: the product's extra ADVISORY readiness checks reach the service signed, in one normalised shape. */
class PostChecksTest extends TestCase
{
    private const SECRET = 'c2VjcmV0LWZvci10ZXN0cy1vbmx5LTMyLWJ5dGVzISE='; // base64

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('growth-client.callback_secret', self::SECRET);
        $app['config']->set('growth-client.post_checks', FakePostChecks::class);
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::webhookRoute('/webhooks/growth-service');
    }

    private function ask(array $body, ?string $signature = null)
    {
        $raw = json_encode($body);
        $ts = (string) time();

        return $this->call('POST', '/webhooks/growth-service/posts/checks', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_SIGNATURE' => $signature ?? WebhookSignature::sign($raw, $ts, base64_decode(self::SECRET)),
            'HTTP_X_GROWTH_TIMESTAMP' => $ts,
        ], $raw);
    }

    private function samplePost(): array
    {
        return ['id' => 'post-uuid-1', 'title' => 'Study permits', 'author_name' => 'Ada', 'cta_kind' => 'booking',
            'cta_ref' => null, 'audience' => 'applicants', 'category_slug' => 'study'];
    }

    public function test_the_products_checks_come_back_normalised_and_malformed_items_are_dropped(): void
    {
        FakePostChecks::with([
            ['key' => 'cicc_s44_byline', 'label' => 'Name the licensee', 'hint' => 'CICC s.44 asks for it.', 'ok' => false, 'required' => true, 'weight' => 0],
            ['key' => '', 'label' => 'no key', 'ok' => true],
            ['key' => 'no_ok', 'label' => 'ok missing'],
            ['key' => 'neg', 'label' => 'Negative weight', 'ok' => true, 'weight' => -3, 'hint' => '  '],
            'not an item',
        ]);

        $this->ask(['post' => $this->samplePost()])
            ->assertOk()
            ->assertJsonCount(2, 'data.checks')
            ->assertJsonPath('data.checks.0.key', 'cicc_s44_byline')
            ->assertJsonPath('data.checks.0.ok', false)
            ->assertJsonPath('data.checks.1.weight', 0)
            ->assertJsonPath('data.checks.1.hint', null)
            ->assertJsonPath('data.checks.1.required', false);

        $this->assertSame('study', FakePostChecks::$calls[0]['category_slug']);
    }

    public function test_none_is_an_empty_list(): void
    {
        FakePostChecks::with([]);

        $this->ask(['post' => $this->samplePost()])->assertOk()->assertJsonPath('data.checks', []);
    }

    public function test_unsigned_missing_id_unavailable_and_unbound(): void
    {
        FakePostChecks::with([]);
        $this->ask(['post' => $this->samplePost()], signature: 'bm90LWEtc2lnbmF0dXJl')->assertStatus(401);
        $this->ask(['post' => ['title' => 'x']])->assertStatus(422);

        FakePostChecks::with([], unavailable: 'Checks are being rebuilt.');
        $this->ask(['post' => $this->samplePost()])->assertStatus(503)->assertJsonPath('message', 'Checks are being rebuilt.');

        config(['growth-client.post_checks' => null]);
        $this->ask(['post' => $this->samplePost()])->assertStatus(503)->assertJsonPath('message', 'This product adds no checks to posts.');
    }
}
