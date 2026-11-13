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
