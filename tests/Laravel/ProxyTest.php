<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\GrowthServiceClient;
use Shirahcan\GrowthClient\Laravel\GrowthActorResolver;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Laravel\Http\GrowthProxyController;

class ProxyTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // The `web` group below encrypts cookies, which needs a key; a real product has its own.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function defineRoutes($router): void
    {
        // A product mounts it inside its admin group. A test-only write row stands in for the
        // real write rows later slices add (G3+).
        Route::middleware('web')->group(fn () => GrowthRoutes::mountAdmin('admin/growth-service'));
    }

    /** @param  list<\Psr\Http\Message\ResponseInterface|\Throwable>  $answers */
    private function service(array $answers, string $estateKey = ''): void
    {
        $stack = HandlerStack::create(new MockHandler($answers));
        $stack->push(Middleware::history($this->history));
        $this->app->instance(GrowthServiceClient::class, new GrowthServiceClient(
            'http://127.0.0.1:8011', 'k-portify', 5, new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8011/']), $estateKey,
        ));
    }

    private static function answer(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function signIn(): User
    {
        $user = new User;
        $user->forceFill(['id' => 42, 'email' => 'ada@example.com']);
        $this->actingAs($user);

        return $user;
    }

    public function test_a_listed_route_reaches_the_service_with_the_products_key(): void
    {
        $this->service([self::answer(200, ['product' => 'portify', 'acting_product' => null])]);

        $this->getJson('/admin/growth-service/whoami')->assertOk()->assertExactJson(['product' => 'portify', 'acting_product' => null]);
        $this->assertSame('Bearer k-portify', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('/api/v1/whoami', $this->history[0]['request']->getUri()->getPath());
    }

    public function test_results_rows_carry_the_period_and_a_settings_change_carries_the_actor(): void
    {
        $this->signIn();
        $this->service([self::answer(200, ['success' => true, 'data' => ['period' => 'month']]), self::answer(200, ['success' => true, 'data' => []])]);

        $this->getJson('/admin/growth-service/results?period=month')->assertOk();
        $this->assertSame('/api/v1/results', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('period=month', $this->history[0]['request']->getUri()->getQuery());

        $this->putJson('/admin/growth-service/settings/results', ['ai_hosts' => ['chatgpt.com']])->assertOk();
        $sent = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('user:42', $sent['actor']['ref']);
    }

    public function test_landing_rows_reach_the_service_and_a_publish_names_the_person(): void
    {
        $this->signIn();
        $uuid = '0b7c2f8e-3d4a-4f5b-9c6d-7e8f9a0b1c2d';
        $this->service([self::answer(200, ['success' => true, 'data' => ['pages' => []]]), self::answer(200, ['success' => true, 'data' => []])]);

        $this->getJson('/admin/growth-service/landing-pages')->assertOk();
        $this->assertSame('/api/v1/landing-pages', $this->history[0]['request']->getUri()->getPath());

        $this->postJson("/admin/growth-service/landing-pages/{$uuid}/publish")->assertOk();
        $this->assertSame("/api/v1/landing-pages/{$uuid}/publish", $this->history[1]['request']->getUri()->getPath());
        $this->assertSame('user:42', json_decode((string) $this->history[1]['request']->getBody(), true)['actor']['ref']);
    }

    public function test_a_slow_row_waits_its_own_timeout_and_every_other_row_keeps_the_clients(): void
    {
        $this->signIn();
        $this->service([
            self::answer(200, ['success' => true, 'data' => ['draft' => ['body_html' => '<p>x</p>', 'preheader' => '']]]),
            self::answer(200, ['product' => 'portify', 'acting_product' => null]),
        ]);

        // A model writing an email takes far longer than the client's default (G5d5: a 503 at 10s on the test box).
        $this->postJson('/admin/growth-service/campaigns/draft', ['subject' => 'Draws', 'kind' => 'regular'])->assertOk();
        $this->assertSame(90, $this->history[0]['options']['timeout'] ?? null);

        $this->getJson('/admin/growth-service/whoami')->assertOk();
        $this->assertArrayNotHasKey('timeout', $this->history[1]['options']);
    }

    public function test_a_path_that_is_not_listed_is_404_and_never_reaches_the_service(): void
    {
        $this->service([]);

        $this->getJson('/admin/growth-service/api/v1/whoami')->assertNotFound();
        $this->getJson('/admin/growth-service/subscribers')->assertNotFound();
        $this->assertSame([], $this->history);
    }

    public function test_the_browser_cannot_set_the_actor(): void
    {
        $this->signIn();
        $this->service([self::answer(200, ['success' => true, 'data' => []])]);
        $this->withWriteRow();

        $this->postJson('/admin/growth-service/_write', ['title' => 'x', 'actor' => ['ref' => 'user:1', 'name' => 'the owner']])->assertOk();

        $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame(['ref' => 'user:42', 'name' => 'ada@example.com'], $sent['actor']);
        $this->assertSame('x', $sent['title']);
    }

    public function test_the_actor_is_named_by_the_users_display_name_and_falls_back_to_their_email(): void
    {
        $user = $this->signIn();
        $user->forceFill(['name' => 'Ada Lovelace']);
        $this->service([self::answer(200, ['success' => true, 'data' => []])]);
        $this->withWriteRow();

        $this->postJson('/admin/growth-service/_write', ['title' => 'x'])->assertOk();

        $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame(['ref' => 'user:42', 'name' => 'Ada Lovelace'], $sent['actor'], 'a person reads the name, never the email, where one exists');
    }

    public function test_a_real_plan_write_row_carries_the_sessions_actor_to_the_service(): void
    {
        $this->signIn();
        $this->service([self::answer(200, ['success' => true, 'data' => ['idea' => ['uuid' => 'u1', 'status' => 'accepted']]])]);

        $this->postJson('/admin/growth-service/plan/ideas/u1/accept', ['planned_for' => '2026-11-20', 'actor' => ['ref' => 'user:1']])
            ->assertOk()->assertJsonPath('data.idea.status', 'accepted');

        $request = $this->history[0]['request'];
        $this->assertSame('/api/v1/plan/ideas/u1/accept', $request->getUri()->getPath());
        $sent = json_decode((string) $request->getBody(), true);
        $this->assertSame(['ref' => 'user:42', 'name' => 'ada@example.com'], $sent['actor']);
        $this->assertSame('2026-11-20', $sent['planned_for']);
    }

    public function test_a_design_reaches_the_service_as_sent_with_its_empty_words_and_spaces(): void
    {
        $this->signIn();
        $this->service([self::answer(200, ['data' => ['tree' => []]])]);
        $nodes = [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => '']], ['primitive' => 'text', 'props' => ['value' => 'Apply ']]]],
            ['primitive' => 'table', 'props' => ['title' => '']]];

        $this->postJson('/admin/growth-service/blog/posts/11111111-1111-4111-8111-111111111111/document/preview', ['nodes' => $nodes])->assertOk();

        $sent = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame($nodes, $sent['nodes'], 'an empty word stays "" (never null) and a space stays, whatever the product\'s global middleware did');
    }

    public function test_a_read_row_never_carries_an_actor_or_a_body(): void
    {
        $this->service([self::answer(200, ['product' => 'portify', 'acting_product' => null])]);

        $this->json('GET', '/admin/growth-service/whoami', ['actor' => 'someone'])->assertOk();
        $this->assertSame('', (string) $this->history[0]['request']->getBody());
    }

    public function test_acting_for_another_product_without_the_estate_key_is_refused_here(): void
    {
        $this->service([]);

        $this->getJson('/admin/growth-service/whoami?as_product=mploynow')
            ->assertForbidden()
            ->assertJsonPath('error', 'estate_not_configured');
        $this->assertSame([], $this->history, 'the service is never asked');
    }

    public function test_acting_for_another_product_uses_the_estate_key(): void
    {
        $this->service([self::answer(200, ['product' => 'mploynow', 'acting_product' => 'portify'])], estateKey: 'k-estate');

        $this->getJson('/admin/growth-service/whoami?as_product=mploynow')->assertOk()->assertJsonPath('acting_product', 'portify');
        $this->assertSame('Bearer k-estate', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('as_product=mploynow', $this->history[0]['request']->getUri()->getQuery());
    }

    public function test_a_service_refusal_comes_back_unchanged(): void
    {
        $this->service([self::answer(422, ['message' => 'A post needs a title.', 'errors' => ['title' => ['A post needs a title.']]])]);

        $this->getJson('/admin/growth-service/whoami')->assertStatus(422)->assertJsonPath('errors.title.0', 'A post needs a title.');
    }

    public function test_a_service_5xx_is_reported_and_answered_plainly(): void
    {
        Exceptions::fake();
        $this->service([self::answer(500, ['message' => 'SQLSTATE[23000] ada@example.com'])]);

        $response = $this->getJson('/admin/growth-service/whoami')->assertStatus(502)->assertJsonPath('error', 'growth_error');
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        Exceptions::assertReported(\RuntimeException::class);
    }

    public function test_an_unreachable_service_is_503_and_reported(): void
    {
        Exceptions::fake();
        $this->service([new ConnectException('Connection refused', new PsrRequest('GET', 'api/v1/whoami'))]);

        $this->getJson('/admin/growth-service/whoami')->assertStatus(503)->assertJsonPath('message', 'Growth is briefly unavailable. Please try again in a minute.');
        Exceptions::assertReported(\Shirahcan\GrowthClient\Exceptions\GrowthServiceUnavailable::class);
    }

    /**
     * TABLE has no write rows yet (they arrive with G3+), so the actor rule is driven through the
     * controller's forRow() seam with a synthetic row marked `actor`.
     */
    private function withWriteRow(): void
    {
        Route::middleware('web')->post('/admin/growth-service/_write', fn (\Illuminate\Http\Request $request) => (new GrowthProxyController)->forRow(
            $request,
            ['POST', '_write', 'api/v1/_write', true],
            app(GrowthServiceClient::class),
            app(GrowthActorResolver::class),
        ));
    }
}
