<?php

namespace Shirahcan\GrowthClient\Tests\Laravel;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Orchestra\Testbench\TestCase;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\GrowthServiceClient;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;

/** G4b: the public door forwards a visitor's own confirm, preferences and unsubscribe, with real evidence. */
class PublicProxyTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV'; // 48

    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        GrowthRoutes::mountPublic('growth');
    }

    private function service(int $status, array $body): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response($status, ['Content-Type' => 'application/json'], json_encode($body))]));
        $stack->push(Middleware::history($this->history));
        $this->app->instance(GrowthServiceClient::class, new GrowthServiceClient(
            'http://127.0.0.1:8011', 'k-portify', 5, new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8011/']),
        ));
    }

    private function sent(): array
    {
        return json_decode((string) $this->history[0]['request']->getBody(), true) ?? [];
    }

    public function test_an_unsubscribe_carries_the_visitors_real_ip_and_browser_never_the_forms(): void
    {
        $this->service(200, ['success' => true, 'data' => ['status' => 'unsubscribed']]);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->withHeader('User-Agent', 'Mozilla/5.0 Real')
            ->postJson('/growth/audience/preferences/'.self::TOKEN.'/unsubscribe', ['reason' => 'too_often', 'ip' => '1.2.3.4', 'user_agent' => 'forged', 'actor' => ['ref' => 'user:1']])
            ->assertOk()->assertJsonPath('data.status', 'unsubscribed');

        $this->assertSame('/api/v1/audience/preferences/'.self::TOKEN.'/unsubscribe', $this->history[0]['request']->getUri()->getPath());
        $this->assertSame('Bearer k-portify', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame(['reason' => 'too_often', 'ip' => '203.0.113.9', 'user_agent' => 'Mozilla/5.0 Real'], $this->sent());
    }

    public function test_a_mail_clients_one_click_form_post_is_forwarded(): void
    {
        $this->service(200, ['success' => true, 'data' => ['status' => 'unsubscribed']]);

        $this->post('/growth/audience/preferences/'.self::TOKEN.'/unsubscribe', ['List-Unsubscribe' => 'One-Click'])->assertOk();

        $this->assertSame('One-Click', $this->sent()['List-Unsubscribe']);
    }

    public function test_preferences_never_add_evidence_and_never_act_for_another_product(): void
    {
        $this->service(200, ['success' => true, 'data' => ['preferences' => []]]);

        $this->getJson('/growth/audience/preferences/'.self::TOKEN.'?as_product=mploynow')->assertOk();

        $this->assertSame('', $this->history[0]['request']->getUri()->getQuery());
    }

    public function test_there_is_no_public_sign_up_and_a_bad_token_never_reaches_the_service(): void
    {
        $this->service(200, []);

        $this->postJson('/growth/audience/subscribe', ['email' => 'a@example.com'])->assertNotFound();
        $this->getJson('/growth/audience/preferences/short')->assertNotFound();
        $this->assertSame([], $this->history);
    }

    public function test_a_service_fault_is_a_plain_502_for_a_visitor(): void
    {
        $this->service(500, ['message' => 'SQLSTATE secret']);

        $this->postJson('/growth/audience/confirm', ['token' => self::TOKEN])
            ->assertStatus(502)->assertJsonPath('message', 'Something went wrong on our side. It has been reported; please try again later.');
    }
}
