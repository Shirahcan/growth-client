<?php

namespace Shirahcan\GrowthClient\Laravel;

use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;
use Shirahcan\GrowthClient\Laravel\Http\GrowthProxyController;
use Shirahcan\GrowthClient\Laravel\Http\WebhookController;

/**
 * The routes a product mounts, each in ONE line:
 *
 *   // inside the product's admin auth group (and behind its own gate, e.g. growth.shared.enabled):
 *   GrowthRoutes::mountAdmin('admin/growth-service');
 *   // public, signed by the service:
 *   GrowthRoutes::webhookRoute('/webhooks/growth-service');
 *
 * source: ai-client `ConsoleRoutes` (a whitelist table, the actor overwritten, `as_product` only with
 * the estate key).
 *
 * ⚠ A WHITELIST, NEVER A PASS-THROUGH. Each row is a decision: a proxy that forwarded "anything under
 * /v1" would hand every signed-in admin a door to every endpoint the service will ever grow. Rows are
 * added slice by slice (G3+), in the same change as the service endpoint and the client call.
 *
 * ⚠ THE ACTOR IS THE PRODUCT'S, NEVER THE BROWSER'S. On a row marked `actor`, the proxy overwrites the
 * body's `actor` with {@see GrowthActorResolver}, so a request cannot claim to be somebody else.
 */
final class GrowthRoutes
{
    /**
     * [method, browser path pattern, upstream path, actor (overwrite `actor` in the body)].
     * `{x}` segments are copied to the upstream path by name.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public const TABLE = [
        // Proves the chain end to end: which product the service resolved from this product's key.
        ['GET', 'whoami', 'api/v1/whoami', false],
    ];

    /** Query parameter naming another product to act for (estate key only, F1 rule 3). */
    public const AS_PRODUCT = 'as_product';

    /** What a route segment may hold: never a slash-walk or a query. */
    public const SEGMENT = '[A-Za-z0-9_.:-]{1,96}';

    public static function mountAdmin(string $prefix = 'growth-service'): void
    {
        Route::prefix(trim($prefix, '/'))->group(function () {
            foreach (self::TABLE as $i => [$method, $path]) {
                preg_match_all('/\{(\w+)\}/', $path, $m);
                $where = array_fill_keys($m[1], self::SEGMENT);

                Route::match([$method], $path, GrowthProxyController::class)
                    ->where($where)
                    ->defaults('_growth_row', $i)
                    ->name('growth-service.'.$i);
            }
        });
    }

    public static function webhookRoute(string $uri = '/webhooks/growth-service'): RouteObject
    {
        return Route::post($uri, WebhookController::class)->name('webhooks.growth-service');
    }
}
