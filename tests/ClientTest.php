<?php

namespace Shirahcan\GrowthClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\GrowthClient\Exceptions\GrowthNotFound;
use Shirahcan\GrowthClient\Exceptions\GrowthRequestRejected;
use Shirahcan\GrowthClient\Exceptions\GrowthServiceException;
use Shirahcan\GrowthClient\Exceptions\GrowthServiceUnavailable;
use Shirahcan\GrowthClient\GrowthServiceClient;

class ClientTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private function client(array $responses): GrowthServiceClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new GrowthServiceClient('http://127.0.0.1:8011', 'k-portify', 5, new Guzzle(['handler' => $stack, 'base_uri' => 'http://127.0.0.1:8011/']));
    }

    private static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function test_whoami_sends_the_bearer_key_and_reads_the_bare_f1_answer(): void
    {
        $who = $this->client([self::json(200, ['product' => 'portify', 'acting_product' => null])])->whoami();

        $this->assertSame(['product' => 'portify', 'acting_product' => null], $who);
        $request = $this->history[0]['request'];
        $this->assertSame('Bearer k-portify', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Accept'));
        $this->assertSame('/api/v1/whoami', $request->getUri()->getPath());
        $this->assertSame('', $request->getUri()->getQuery(), 'the key never travels in the URL');
    }

    public function test_as_product_adds_the_query_on_a_new_client_and_leaves_the_original_alone(): void
    {
        $client = $this->client([
            self::json(200, ['product' => 'mploynow', 'acting_product' => 'portify']),
            self::json(200, ['product' => 'portify', 'acting_product' => null]),
        ]);

        $this->assertSame('portify', $client->asProduct('mploynow')->whoami()['acting_product']);
        $this->assertSame('as_product=mploynow', $this->history[0]['request']->getUri()->getQuery());

        $client->whoami();
        $this->assertSame('', $this->history[1]['request']->getUri()->getQuery());
    }

    public function test_the_house_envelope_returns_its_data(): void
    {
        $client = new class('http://x', 'k', 5, new Guzzle(['handler' => HandlerStack::create(new MockHandler([self::json(200, ['success' => true, 'message' => 'ok', 'data' => ['id' => 7]])])), 'base_uri' => 'http://x/'])) extends GrowthServiceClient
        {
            public function probe(): array
            {
                return $this->send('GET', 'api/v1/anything');
            }
        };

        $this->assertSame(['id' => 7], $client->probe());
    }

    public function test_a_revoked_key_is_a_reported_fault_and_never_shows_the_services_text(): void
    {
        try {
            $this->client([self::json(401, ['error' => 'unauthorized', 'message' => 'A valid product trust key is required.'])])->whoami();
            $this->fail('expected a refusal');
        } catch (GrowthServiceException $e) {
            $this->assertSame(GrowthServiceException::class, $e::class);
            $this->assertSame('unauthorized', $e->errorCode);
            $this->assertTrue($e->shouldReport());
            $this->assertStringNotContainsString('trust key', $e->userMessage());
        }
    }

    public function test_a_product_key_naming_another_product_is_a_reported_forbidden(): void
    {
        try {
            $this->client([self::json(403, ['error' => 'forbidden', 'message' => 'Only the estate admin key may read or change another product\'s Growth data.'])])
                ->asProduct('mploynow')->whoami();
            $this->fail('expected a refusal');
        } catch (GrowthServiceException $e) {
            $this->assertSame(GrowthServiceException::class, $e::class);
            $this->assertSame(['forbidden', 403], [$e->errorCode, $e->status]);
            $this->assertTrue($e->shouldReport());
        }
    }

    public function test_not_found_is_quiet_and_never_confirms_another_products_record(): void
    {
        try {
            $this->client([self::json(404, ['success' => false, 'message' => 'Not found'])])->whoami();
            $this->fail('expected a refusal');
        } catch (GrowthNotFound $e) {
            $this->assertFalse($e->shouldReport());
            $this->assertSame('That item could not be found. It may have been removed.', $e->userMessage());
        }
    }

    public function test_a_validation_refusal_carries_the_services_sentence_and_its_fields(): void
    {
        try {
            $this->client([self::json(422, ['message' => 'A post needs a title.', 'errors' => ['title' => ['A post needs a title.']]])])->whoami();
            $this->fail('expected a refusal');
        } catch (GrowthRequestRejected $e) {
            $this->assertSame('A post needs a title.', $e->userMessage());
            $this->assertSame(['title' => ['A post needs a title.']], $e->errors);
            $this->assertFalse($e->shouldReport());
        }
    }

    public function test_a_conflict_is_a_rejection_with_code_conflict(): void
    {
        try {
            $this->client([self::json(409, ['success' => false, 'message' => 'Someone else saved this a moment ago.'])])->whoami();
            $this->fail('expected a refusal');
        } catch (GrowthRequestRejected $e) {
            $this->assertSame('conflict', $e->errorCode);
        }
    }

    /** @return iterable<string, array{0: \Psr\Http\Message\ResponseInterface|\Throwable}> */
    public static function outages(): iterable
    {
        yield '500' => [self::json(500, ['message' => 'Server Error'])];
        yield '503' => [self::json(503, ['status' => 'unavailable'])];
        yield '429' => [self::json(429, ['message' => 'Too Many Attempts.'])];
        yield 'unreachable' => [new ConnectException('Connection refused', new Request('GET', 'api/v1/whoami'))];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('outages')]
    public function test_an_outage_fails_closed_with_a_plain_sentence_and_is_reported($answer): void
    {
        try {
            $this->client([$answer])->whoami();
            $this->fail('expected an outage');
        } catch (GrowthServiceUnavailable $e) {
            $this->assertTrue($e->shouldReport());
            $this->assertSame('Growth is briefly unavailable. Please try again in a minute.', $e->userMessage());
        }
    }
}
