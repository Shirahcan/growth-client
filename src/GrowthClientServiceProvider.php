<?php

namespace Shirahcan\GrowthClient;

use Illuminate\Support\ServiceProvider;
use RuntimeException;

class GrowthClientServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/growth-client.php', 'growth-client');

        $this->app->singleton(GrowthClient::class, function () {
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
            );
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/growth-client.php' => config_path('growth-client.php'),
        ], 'growth-client-config');
    }
}
