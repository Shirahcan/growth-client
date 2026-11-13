<?php

namespace Shirahcan\GrowthClient\Laravel;

use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;
use Shirahcan\GrowthClient\Laravel\Http\GrowthProxyController;
use Shirahcan\GrowthClient\Laravel\Http\FactsController;
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

        // Plan and Radar (G3a). Every write records who decided (actor).
        ['GET', 'plan/ideas', 'api/v1/plan/ideas', false],
        ['GET', 'plan/ideas/{uuid}', 'api/v1/plan/ideas/{uuid}', false],
        ['POST', 'plan/ideas/{uuid}/accept', 'api/v1/plan/ideas/{uuid}/accept', true],
        ['POST', 'plan/ideas/{uuid}/skip', 'api/v1/plan/ideas/{uuid}/skip', true],
        ['POST', 'plan/ideas/{uuid}/undo', 'api/v1/plan/ideas/{uuid}/undo', true],
        ['POST', 'plan/ideas/{uuid}/schedule', 'api/v1/plan/ideas/{uuid}/schedule', true],
        ['GET', 'plan/calendar', 'api/v1/plan/calendar', false],
        ['GET', 'plan/radar', 'api/v1/plan/radar', false],
        ['GET', 'plan/radar/{id}', 'api/v1/plan/radar/{id}', false],
        ['POST', 'plan/radar/{id}/dismiss', 'api/v1/plan/radar/{id}/dismiss', true],

        // Watched official sources (G3a). "Check now" and the radar settings arrive with G3b / G3c.
        ['GET', 'watched-sources', 'api/v1/watched-sources', false],
        ['GET', 'watched-sources/{id}', 'api/v1/watched-sources/{id}', false],
        ['POST', 'watched-sources', 'api/v1/watched-sources', true],
        ['PUT', 'watched-sources/{id}', 'api/v1/watched-sources/{id}', true],
        ['DELETE', 'watched-sources/{id}', 'api/v1/watched-sources/{id}', true],
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

    /**
     * The service's two signed ways in: `$uri` receives events, `$uri/facts` answers "what is the
     * figure now?" from the product's FactsAdapter (G3c). Returns the event route.
     */
    public static function webhookRoute(string $uri = '/webhooks/growth-service'): RouteObject
    {
        Route::post(rtrim($uri, '/').'/facts', FactsController::class)->name('webhooks.growth-service.facts');

        return Route::post($uri, WebhookController::class)->name('webhooks.growth-service');
    }
}
