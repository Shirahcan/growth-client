<?php

namespace Shirahcan\GrowthClient\Tests;

use Orchestra\Testbench\TestCase;
use RuntimeException;
use Shirahcan\GrowthClient\GrowthClient;
use Shirahcan\GrowthClient\GrowthClientServiceProvider;
use Shirahcan\GrowthClient\GrowthServiceClient;

class ProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [GrowthClientServiceProvider::class];
    }

    public function test_an_unset_key_fails_loudly_when_the_client_is_resolved(): void
    {
        config(['growth-client.trust_key' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GROWTH_SERVICE_TRUST_KEY is not set');
        app(GrowthClient::class);
    }

    public function test_a_set_key_resolves_the_loopback_client(): void
    {
        config(['growth-client.trust_key' => 'k-portify']);

        $this->assertInstanceOf(GrowthServiceClient::class, app(GrowthClient::class));
        $this->assertSame('http://127.0.0.1:8011', config('growth-client.base_url'));
    }
}
