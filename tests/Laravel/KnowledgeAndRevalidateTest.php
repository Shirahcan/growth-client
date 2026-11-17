<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Testing\FakeKnowledgeSink;
use Shirahcan\GrowthClient\Testing\FakeRevalidate;
use Shirahcan\GrowthClient\WebhookSignature;

/**
 * S4a (blog-on-growth-service): the service's signed knowledge and revalidate calls reach the product's adapters.
 * The service decides add-to-knowledge; the product decides served or held and must be idempotent.
 */
class KnowledgeAndRevalidateTest extends TestCase
{
    private const SECRET = 'c2VjcmV0LWZvci10ZXN0cy1vbmx5LTMyLWJ5dGVzISE='; // base64

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('growth-client.callback_secret', self::SECRET);
        $app['config']->set('growth-client.knowledge_sink', FakeKnowledgeSink::class);
        $app['config']->set('growth-client.revalidate', FakeRevalidate::class);
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::webhookRoute('/webhooks/growth-service');
    }

    protected function setUp(): void
    {
        parent::setUp();
        FakeKnowledgeSink::reset();
        FakeRevalidate::reset();
    }

    private function send(string $path, array $body, ?string $signature = null)
    {
        $raw = json_encode($body);
        $ts = (string) time();

        return $this->call('POST', '/webhooks/growth-service/'.$path, [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GROWTH_SIGNATURE' => $signature ?? WebhookSignature::sign($raw, $ts, base64_decode(self::SECRET)),
            'HTTP_X_GROWTH_TIMESTAMP' => $ts,
        ], $raw);
    }

    private function samplePost(array $over = []): array
    {
        return array_merge([
            'id' => 'post-uuid-1', 'slug' => 'study-permits', 'title' => 'Study permits explained', 'answer' => 'Short answer.',
            'meta_description' => 'What a study permit is.', 'audience' => 'applicants', 'category' => ['slug' => 'study', 'name' => 'Study'],
            'reader_questions' => [['q' => 'How long?', 'a' => 'Weeks.']],
            'facts' => [['text' => 'The study permit fee is $150.', 'review_by' => '2027-01-01', 'source_key' => 'ircc.fees.study_permit']],
            'published_at' => '2026-11-17T10:00:00+00:00',
        ], $over);
    }

    public function test_an_upsert_reaches_the_product_whole_and_twice_is_one_row(): void
    {
        $this->send('knowledge/upsert', ['post' => $this->samplePost()])->assertOk()->assertJsonPath('data.state', 'served');
        $this->send('knowledge/upsert', ['post' => $this->samplePost()])->assertOk();

        $this->assertCount(1, FakeKnowledgeSink::$rows, 'a retried upsert never writes twice');
        $this->assertSame('ircc.fees.study_permit', FakeKnowledgeSink::$rows['post-uuid-1']['facts'][0]['source_key']);
        $this->assertArrayNotHasKey('url', FakeKnowledgeSink::$rows['post-uuid-1'], 'the product builds the link itself');
    }

    public function test_the_product_may_hold_a_post_and_says_why(): void
    {
        FakeKnowledgeSink::reset(heldReason: 'The public blog is off, so the link would not open.');

        $this->send('knowledge/upsert', ['post' => $this->samplePost()])
            ->assertOk()
            ->assertJsonPath('data.state', 'held')
            ->assertJsonPath('data.reason', 'The public blog is off, so the link would not open.');
    }

    public function test_remove_is_idempotent(): void
    {
        $this->send('knowledge/upsert', ['post' => $this->samplePost()]);

        $this->send('knowledge/remove', ['id' => 'post-uuid-1', 'reason' => 'unpublished'])->assertOk()->assertJsonPath('data.state', 'removed');
        $this->send('knowledge/remove', ['id' => 'post-uuid-1', 'reason' => 'unpublished'])->assertOk()->assertJsonPath('data.state', 'none');
    }

    public function test_knowledge_refuses_unsigned_missing_ids_and_answers_unavailable(): void
    {
        $this->send('knowledge/upsert', ['post' => $this->samplePost()], signature: 'bm90LWEtc2lnbmF0dXJl')->assertStatus(401);
        $this->send('knowledge/upsert', ['post' => $this->samplePost(['id' => ''])])->assertStatus(422);
        $this->send('knowledge/remove', ['reason' => 'x'])->assertStatus(422);

        FakeKnowledgeSink::reset(unavailable: 'Knowledge is being rebuilt.');
        $this->send('knowledge/upsert', ['post' => $this->samplePost()])->assertStatus(503)->assertJsonPath('message', 'Knowledge is being rebuilt.');

        config(['growth-client.knowledge_sink' => null]);
        $this->send('knowledge/remove', ['id' => 'x'])->assertStatus(503)->assertJsonPath('message', 'This product does not take posts into its knowledge.');
    }

    public function test_revalidate_passes_a_neutral_change_and_a_failed_bust_is_honest_not_fatal(): void
    {
        $this->send('revalidate', ['event' => 'updated', 'slug' => 'new-slug', 'previous_slug' => 'old-slug', 'category_slugs' => ['study', '', 7]])
            ->assertOk()
            ->assertJsonPath('data.revalidated', true);

        $this->assertSame(
            ['event' => 'updated', 'slug' => 'new-slug', 'previous_slug' => 'old-slug', 'category_slugs' => ['study']],
            FakeRevalidate::$calls[0],
            'blank and non-string category slugs are dropped; the shape is the same for every product',
        );

        FakeRevalidate::reset(failure: 'The frontend did not answer.');
        $this->send('revalidate', ['event' => 'deleted', 'slug' => 'gone'])
            ->assertOk()
            ->assertJsonPath('data.revalidated', false)
            ->assertJsonPath('data.detail', 'The frontend did not answer.');
        $this->assertNull(FakeRevalidate::$calls[0]['previous_slug']);
    }

    public function test_revalidate_refuses_unsigned_unknown_events_and_answers_unavailable(): void
    {
        $this->send('revalidate', ['event' => 'published', 'slug' => 's'], signature: 'bm90LWEtc2lnbmF0dXJl')->assertStatus(401);
        $this->send('revalidate', ['event' => 'archived', 'slug' => 's'])->assertStatus(422);
        $this->send('revalidate', ['event' => 'published', 'slug' => ''])->assertStatus(422);

        config(['growth-client.revalidate' => null]);
        $this->send('revalidate', ['event' => 'published', 'slug' => 's'])->assertStatus(503)->assertJsonPath('message', 'This product does not cache its public pages.');
    }
}
