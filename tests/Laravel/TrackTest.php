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

/** G5d2: the pixel always answers an image; a click redirects only where the service vouched for. */
class TrackTest extends TestCase
{
    private const TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEF'; // 32

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

    public function test_the_pixel_is_an_image_whatever_the_service_answers(): void
    {
        foreach ([[200, ['success' => true]], [404, ['message' => 'Not found.']], [500, []]] as [$status, $body]) {
            $this->history = [];
            $this->service($status, $body);
            $res = $this->get('/growth/track/'.self::TOKEN.'/open');
            $res->assertOk()->assertHeader('Content-Type', 'image/gif');
            $this->assertSame('/api/v1/track/'.self::TOKEN.'/open', $this->history[0]['request']->getUri()->getPath());
            $this->assertSame('Bearer k-portify', $this->history[0]['request']->getHeaderLine('Authorization'));
        }
    }

    public function test_a_click_redirects_only_where_the_service_says_and_carries_the_link_and_signature(): void
    {
        $this->service(200, ['success' => true, 'data' => ['redirect' => 'https://www.canada.ca/en/news.html']]);
        $this->get('/growth/track/'.self::TOKEN.'/click?u='.urlencode('https://www.canada.ca/en/news.html').'&s=abc')
            ->assertRedirect('https://www.canada.ca/en/news.html');
        parse_str($this->history[0]['request']->getUri()->getQuery(), $q);
        $this->assertSame(['u' => 'https://www.canada.ca/en/news.html', 's' => 'abc'], $q);

        $this->history = [];
        $this->service(422, ['message' => 'This link is not one we sent.']);
        $this->get('/growth/track/'.self::TOKEN.'/click?u='.urlencode('https://evil.example/').'&s=x')->assertNotFound();

        $this->history = [];
        $this->service(200, ['success' => true, 'data' => ['redirect' => 'javascript:alert(1)']]);
        $this->get('/growth/track/'.self::TOKEN.'/click?u=x&s=y')->assertNotFound();

        $this->get('/growth/track/short/open')->assertNotFound(); // not a token this service issues
    }
}
