<?php

namespace Shirahcan\GrowthClient\Tests;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Shirahcan\GrowthClient\Exceptions\GrowthNotFound;
use Shirahcan\GrowthClient\Exceptions\GrowthServiceException;
use Shirahcan\GrowthClient\GrowthServiceClient;
use Shirahcan\GrowthClient\Testing\FakeGrowth;

/**
 * The fake refuses what the service refuses, with the same exception, code and status the real client
 * produces from the service's own answer (bodies copied from growth-service's ActsForProduct).
 */
class FakeKeepsTheServicesContractTest extends TestCase
{
    private static function real(int $status, array $body): GrowthServiceClient
    {
        $mock = new MockHandler([new Response($status, ['Content-Type' => 'application/json'], json_encode($body))]);

        return new GrowthServiceClient('http://x', 'k', 5, new Guzzle(['handler' => HandlerStack::create($mock), 'base_uri' => 'http://x/']));
    }

    /** @return array{0: class-string, 1: string, 2: int} */
    private static function caught(callable $call): array
    {
        try {
            $call();
        } catch (GrowthServiceException $e) {
            return [$e::class, $e->errorCode, $e->status];
        }

        return ['none', '', 0];
    }

    public function test_the_product_is_the_fakes_own(): void
    {
        $this->assertSame(['product' => 'mploynow', 'acting_product' => null], FakeGrowth::forProduct('mploynow')->whoami());
    }

    public function test_a_product_fake_naming_another_product_is_refused_like_the_service(): void
    {
        $fake = self::caught(fn () => FakeGrowth::forProduct('portify')->asProduct('mploynow'));
        $real = self::caught(fn () => self::real(403, ['error' => 'forbidden', 'message' => 'Only the estate admin key may read or change another product\'s Growth data.'])->asProduct('mploynow')->whoami());

        $this->assertSame([GrowthServiceException::class, 'forbidden', 403], $fake);
        $this->assertSame($real, $fake);
    }

    public function test_an_unknown_product_is_refused_like_the_service(): void
    {
        $fake = self::caught(fn () => FakeGrowth::estate()->asProduct('finance'));
        $real = self::caught(fn () => self::real(404, ['error' => 'unknown_product', 'message' => 'There is no product [finance].'])->asProduct('finance')->whoami());

        $this->assertSame([GrowthNotFound::class, 'unknown_product', 404], $fake);
        $this->assertSame($real, $fake);
    }

    public function test_the_estate_fake_acts_for_another_product_and_keeps_who_acted(): void
    {
        $fake = FakeGrowth::estate('portify');

        $this->assertSame(['product' => 'mploynow', 'acting_product' => 'portify'], $fake->asProduct('mploynow')->whoami());
        $this->assertSame(['product' => 'portify', 'acting_product' => null], $fake->whoami(), 'the original is untouched');
    }

    public function test_naming_your_own_product_is_a_no_op(): void
    {
        $fake = FakeGrowth::forProduct('portify');

        $this->assertSame($fake, $fake->asProduct('portify'));
    }

    public function test_calls_are_recorded_across_clones_with_their_product(): void
    {
        $fake = FakeGrowth::estate('portify');
        $fake->whoami();
        $fake->asProduct('mploynow')->whoami();

        $this->assertSame(
            [['portify', null], ['mploynow', 'portify']],
            array_map(fn (array $c) => [$c['product'], $c['acting']], $fake->calls('whoami')),
        );
    }
}
