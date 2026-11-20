<?php

namespace Shirahcan\GrowthClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\GrowthClient\Exceptions\GrowthRequestRejected;
use Shirahcan\GrowthClient\GrowthServiceClient;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;
use Shirahcan\GrowthClient\Testing\FakeGrowth;

/**
 * Public images plan P3: a product's SERVER adds a picture to its Growth library (multipart, never through the JSON
 * proxy); its admins list, describe and remove pictures through four whitelisted proxy rows.
 */
class MediaTest extends TestCase
{
    /** @var list<array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'pic');
        file_put_contents($this->file, 'picture bytes');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

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

    public function test_upload_sends_the_file_origin_actor_and_old_address_as_multipart_with_the_product_key(): void
    {
        $client = $this->client([self::json(201, ['success' => true, 'data' => [
            'media' => ['id' => 'm1', 'url' => 'https://portify.shirah.ca/media/m1.jpg'], 'quota' => ['files_used' => 1],
        ]])]);

        $answer = $client->uploadMedia($this->file, 'cover.jpg', 'https://portify.shirah.ca', ['ref' => 'user:7', 'name' => 'Ada'], 'A family', '/storage/blog/images/a.jpg');

        $this->assertSame('https://portify.shirah.ca/media/m1.jpg', $answer['media']['url']);
        $request = $this->history[0]['request'];
        $body = (string) $request->getBody();
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('/api/v1/media', $request->getUri()->getPath());
        $this->assertSame('Bearer k-portify', $request->getHeaderLine('Authorization'));
        $this->assertStringContainsString('multipart/form-data', $request->getHeaderLine('Content-Type'));
        foreach (['name="image"; filename="cover.jpg"', 'picture bytes', 'name="origin"', 'https://portify.shirah.ca', 'name="actor[ref]"', 'user:7', 'name="source_url"', '/storage/blog/images/a.jpg', 'name="alt"'] as $part) {
            $this->assertStringContainsString($part, $body);
        }
    }

    public function test_a_refusal_comes_back_with_the_sentence_the_person_reads(): void
    {
        $client = $this->client([self::json(422, ['success' => false, 'message' => 'This library is full. Remove a picture you are not using and try again.'])]);

        try {
            $client->uploadMedia($this->file, 'cover.jpg', 'https://portify.shirah.ca', ['ref' => 'user:7', 'name' => 'Ada']);
            $this->fail('A 422 must throw.');
        } catch (GrowthRequestRejected $refused) {
            $this->assertSame('This library is full. Remove a picture you are not using and try again.', $refused->userMessage());
        }
    }

    public function test_the_library_rows_are_whitelisted_with_the_actor_on_every_change_and_no_upload_row(): void
    {
        $rows = collect(GrowthRoutes::TABLE)->keyBy(fn (array $r) => $r[0].' '.$r[1]);

        $this->assertSame(['GET', 'media', 'api/v1/media', false], $rows['GET media']);
        $this->assertSame(['GET', 'media/{id}', 'api/v1/media/{id}', false], $rows['GET media/{id}']);
        $this->assertSame(['PATCH', 'media/{id}', 'api/v1/media/{id}', true], $rows['PATCH media/{id}']);
        $this->assertSame(['DELETE', 'media/{id}', 'api/v1/media/{id}', true], $rows['DELETE media/{id}']);
        $this->assertArrayNotHasKey('POST media', $rows->all(), 'a file never goes through the JSON proxy');
    }

    public function test_the_fake_keeps_the_same_contract_and_copies_an_old_address_once(): void
    {
        $fake = FakeGrowth::forProduct('portify');

        $first = $fake->uploadMedia($this->file, 'old.jpg', 'https://portify.test', ['ref' => 'user:1', 'name' => ''], null, '/storage/blog/images/old.jpg');
        $again = $fake->uploadMedia($this->file, 'old.jpg', 'https://portify.test', ['ref' => 'user:1', 'name' => ''], null, '/storage/blog/images/old.jpg');

        $this->assertSame($first['media']['id'], $again['media']['id']);
        $this->assertStringStartsWith('https://portify.test/media/', $first['media']['url']);
        $this->assertSame(1, $again['quota']['files_used']);

        $fake->refuseNextUpload = 'That file is not a picture we can show. Use a JPG, PNG, WebP or GIF.';
        $this->expectException(GrowthRequestRejected::class);
        $fake->uploadMedia($this->file, 'logo.svg', 'https://portify.test', ['ref' => 'user:1', 'name' => '']);
    }
}
