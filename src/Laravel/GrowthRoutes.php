<?php

namespace Shirahcan\GrowthClient\Laravel;

use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;
use Shirahcan\GrowthClient\Laravel\Http\GrowthProxyController;
use Shirahcan\GrowthClient\Laravel\Http\GrowthPublicProxyController;
use Shirahcan\GrowthClient\Laravel\Http\FactsController;
use Shirahcan\GrowthClient\Laravel\Http\PlanSourcesController;
use Shirahcan\GrowthClient\Laravel\Http\SegmentsController;
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
        // "Suggest more" (G3d3): an explicit ask, so ideas are written even if some were made today.
        ['POST', 'plan/ideas/more', 'api/v1/plan/ideas/more', true],
        ['GET', 'plan/ideas/{uuid}', 'api/v1/plan/ideas/{uuid}', false],
        ['POST', 'plan/ideas/{uuid}/accept', 'api/v1/plan/ideas/{uuid}/accept', true],
        ['POST', 'plan/ideas/{uuid}/skip', 'api/v1/plan/ideas/{uuid}/skip', true],
        ['POST', 'plan/ideas/{uuid}/undo', 'api/v1/plan/ideas/{uuid}/undo', true],
        ['POST', 'plan/ideas/{uuid}/schedule', 'api/v1/plan/ideas/{uuid}/schedule', true],
        ['GET', 'plan/calendar', 'api/v1/plan/calendar', false],
        ['GET', 'plan/radar', 'api/v1/plan/radar', false],
        ['GET', 'plan/radar/{id}', 'api/v1/plan/radar/{id}', false],
        ['POST', 'plan/radar/{id}/dismiss', 'api/v1/plan/radar/{id}/dismiss', true],

        // Watched official sources (G3a); "Check now" (G3b, built-in feeds through the product's facts since G3g).
        ['GET', 'watched-sources', 'api/v1/watched-sources', false],
        ['GET', 'watched-sources/{id}', 'api/v1/watched-sources/{id}', false],
        ['POST', 'watched-sources', 'api/v1/watched-sources', true],
        ['PUT', 'watched-sources/{id}', 'api/v1/watched-sources/{id}', true],
        ['DELETE', 'watched-sources/{id}', 'api/v1/watched-sources/{id}', true],
        ['POST', 'watched-sources/{id}/check', 'api/v1/watched-sources/{id}/check', true],

        // The radar's settings (G3e5): on/off, tracked countries, thresholds, cadence.
        ['GET', 'settings/radar', 'api/v1/settings/radar', false],
        ['PUT', 'settings/radar', 'api/v1/settings/radar', true],

        // Audience (G4e4a). People: list, the consent trail, the admin's two actions.
        ['GET', 'audience/subscribers', 'api/v1/audience/subscribers', false],
        ['GET', 'audience/subscribers/{uuid}', 'api/v1/audience/subscribers/{uuid}', false],
        ['PUT', 'audience/subscribers/{uuid}/audience', 'api/v1/audience/subscribers/{uuid}/audience', true],
        ['POST', 'audience/subscribers/{uuid}/suppress', 'api/v1/audience/subscribers/{uuid}/suppress', true],
        // Lists and saved audiences (G4c1, G4d2); "who would this reach" is a read.
        ['GET', 'audience/lists', 'api/v1/audience/lists', false],
        ['POST', 'audience/lists', 'api/v1/audience/lists', true],
        ['PUT', 'audience/lists/{uuid}', 'api/v1/audience/lists/{uuid}', true],
        ['DELETE', 'audience/lists/{uuid}', 'api/v1/audience/lists/{uuid}', true],
        ['GET', 'audience/segments', 'api/v1/audience/segments', false],
        ['POST', 'audience/segments', 'api/v1/audience/segments', true],
        ['PUT', 'audience/segments/{uuid}', 'api/v1/audience/segments/{uuid}', true],
        ['DELETE', 'audience/segments/{uuid}', 'api/v1/audience/segments/{uuid}', true],
        ['POST', 'audience/estimate', 'api/v1/audience/estimate', false],
        ['GET', 'audience/platform-groups', 'api/v1/audience/platform-groups', false],
        ['GET', 'audience/people', 'api/v1/audience/people', false],
        ['GET', 'audience/lapsing', 'api/v1/audience/lapsing', false],
        // Imports (G4c2): the browser parses the file and sends rows; preview stores nothing.
        ['GET', 'audience/imports', 'api/v1/audience/imports', false],
        ['POST', 'audience/imports/preview', 'api/v1/audience/imports/preview', false],
        ['POST', 'audience/imports', 'api/v1/audience/imports', true],
        ['GET', 'audience/imports/{uuid}', 'api/v1/audience/imports/{uuid}', false],
        ['POST', 'audience/imports/{uuid}/rows', 'api/v1/audience/imports/{uuid}/rows', true],
        ['POST', 'audience/imports/{uuid}/start', 'api/v1/audience/imports/{uuid}/start', true],
        ['POST', 'audience/imports/{uuid}/stop', 'api/v1/audience/imports/{uuid}/stop', true],
        // Audience settings (G4c2/G4c3): double opt-in, topics, regular gap, purge period, lapse warning.
        ['GET', 'settings/audience', 'api/v1/settings/audience', false],
        ['PUT', 'settings/audience', 'api/v1/settings/audience', true],
    ];

    /** Query parameter naming another product to act for (estate key only, F1 rule 3). */
    public const AS_PRODUCT = 'as_product';

    /** What a route segment may hold: never a slash-walk or a query. */
    public const SEGMENT = '[A-Za-z0-9_.:-]{1,96}';

    /**
     * The PUBLIC rows (G4b): [method, path, upstream, evidence (add the visitor's IP and browser)].
     * Reached with the token from the person's own email. Sign-up is deliberately absent: see
     * {@see Http\GrowthPublicProxyController}.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public const PUBLIC_TABLE = [
        ['POST', 'audience/confirm', 'api/v1/audience/confirm', true],
        ['GET', 'audience/preferences/{token}', 'api/v1/audience/preferences/{token}', false],
        ['PUT', 'audience/preferences/{token}', 'api/v1/audience/preferences/{token}', false],
        ['POST', 'audience/preferences/{token}/unsubscribe', 'api/v1/audience/preferences/{token}/unsubscribe', true],
    ];

    /** Mount the public rows under the product's own public prefix; wrap it in a per-visitor throttle. */
    public static function mountPublic(string $prefix = 'growth'): void
    {
        Route::prefix(trim($prefix, '/'))->group(function () {
            foreach (self::PUBLIC_TABLE as $i => [$method, $path]) {
                Route::match([$method], $path, GrowthPublicProxyController::class)
                    ->where(['token' => '[A-Za-z0-9]{48}'])
                    ->defaults('_growth_public_row', $i)
                    ->name('growth-public.'.$i);
            }
        });
    }

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
        Route::post(rtrim($uri, '/').'/plan-sources', PlanSourcesController::class)->name('webhooks.growth-service.plan-sources');
        // G4d1: the product's people groups (catalogue, expand to email hashes, search), signed like facts.
        Route::post(rtrim($uri, '/').'/segments', [SegmentsController::class, 'catalog'])->name('webhooks.growth-service.segments');
        Route::post(rtrim($uri, '/').'/segments/expand', [SegmentsController::class, 'expand'])->name('webhooks.growth-service.segments.expand');
        Route::post(rtrim($uri, '/').'/segments/search', [SegmentsController::class, 'search'])->name('webhooks.growth-service.segments.search');

        return Route::post($uri, WebhookController::class)->name('webhooks.growth-service');
    }
}
