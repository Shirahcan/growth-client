<?php

namespace Shirahcan\GrowthClient;

use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Shirahcan\GrowthClient\Laravel\GrowthActorResolver;
use Shirahcan\GrowthClient\Laravel\SessionGrowthActor;

class GrowthClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/growth-client.php', 'growth-client');

        $this->app->singleton(GrowthServiceClient::class, function () {
            $key = (string) config('growth-client.trust_key', '');

            /*
             * ⚠ FAIL LOUDLY AT RESOLUTION, NOT SILENTLY AT THE FIRST CALL. An unset key is a deployment
             * mistake, far cheaper to find here than as a 401 in front of an admin. A product whose
             * Growth has not moved yet never resolves this.
             */
            if ($key === '') {
                throw new RuntimeException(
                    'growth-client: GROWTH_SERVICE_TRUST_KEY is not set. Issue one on the service with '
                    .'`php artisan growth:issue-key <product>` and put it in this app\'s .env.'
                );
            }

            return new GrowthServiceClient(
                baseUrl: (string) config('growth-client.base_url'),
                trustKey: $key,
                timeout: (int) config('growth-client.timeout', 10),
                estateKey: (string) config('growth-client.estate_key', ''),
            );
        });

        // Product code types against the interface; the proxy needs the concrete forward().
        $this->app->singleton(GrowthClient::class, fn ($app) => $app->make(GrowthServiceClient::class));

        $this->app->bind(GrowthActorResolver::class, function ($app) {
            $class = config('growth-client.actor');

            return is_string($class) && $class !== '' ? $app->make($class) : new SessionGrowthActor;
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/growth-client.php' => config_path('growth-client.php'),
        ], 'growth-client-config');

        // The kit's inbox for signed callbacks; a no-op where the product already has the table.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
