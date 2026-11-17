<?php

namespace Shirahcan\GrowthClient\Laravel;

use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;
use Shirahcan\GrowthClient\Laravel\Http\GrowthProxyController;
use Shirahcan\GrowthClient\Laravel\Http\GrowthPublicProxyController;
use Shirahcan\GrowthClient\Laravel\Http\GrowthTrackController;
use Shirahcan\GrowthClient\Laravel\Http\AnnouncementReachController;
use Shirahcan\GrowthClient\Laravel\Http\FactsController;
use Shirahcan\GrowthClient\Laravel\Http\PlanSourcesController;
use Shirahcan\GrowthClient\Laravel\Http\ReviewersController;
use Shirahcan\GrowthClient\Laravel\Http\KnowledgeSinkController;
use Shirahcan\GrowthClient\Laravel\Http\RevalidateController;
use Shirahcan\GrowthClient\Laravel\Http\PostChecksController;
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
     * @var list<array{0: string, 1: string, 2: string, 3: bool, 4?: int}>  4: a slow row's own timeout, seconds
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

        // The brand kit (G2e): name, logo, colours, fonts, legal links; the sender is shown read-only.
        ['GET', 'settings/brand', 'api/v1/settings/brand', false],
        ['PUT', 'settings/brand', 'api/v1/settings/brand', true],

        // The CTA library (post-and-newsletter-layouts B1): copy per stage, variant and category; `resolve` is what a
        // post's CTA part reads ("not configured" when nothing is set up).
        ['GET', 'ctas', 'api/v1/ctas', false],
        ['GET', 'ctas/resolve', 'api/v1/ctas/resolve', false],
        ['POST', 'ctas', 'api/v1/ctas', true],
        ['PUT', 'ctas/{id}', 'api/v1/ctas/{id}', true],
        ['DELETE', 'ctas/{id}', 'api/v1/ctas/{id}', true],

        // The story bank (B5): consented, anonymised, verified client stories; `?pickable=1` lists what a part may show.
        ['GET', 'stories', 'api/v1/stories', false],
        ['GET', 'stories/{id}', 'api/v1/stories/{id}', false],
        ['POST', 'stories', 'api/v1/stories', true],
        ['PUT', 'stories/{id}', 'api/v1/stories/{id}', true],
        ['POST', 'stories/{id}/verify', 'api/v1/stories/{id}/verify', true],
        ['DELETE', 'stories/{id}', 'api/v1/stories/{id}', true],

        // Layouts (A4): the skeletons posts and emails are built on; an admin edit is validated by pml-kit.
        ['GET', 'layouts', 'api/v1/layouts', false],
        ['GET', 'layouts/{key}', 'api/v1/layouts/{key}', false],
        ['PUT', 'layouts/{key}', 'api/v1/layouts/{key}', true],

        // Reviewers (B3): who may be named as a post's reviewer, read from the product by growth-service.
        ['GET', 'reviewers', 'api/v1/reviewers', false],

        // Blog categories (S4b, blog-on-growth-service; growth-service v0.37.0 S1): reads carry no actor, every change does.
        ['GET', 'blog/categories', 'api/v1/blog/categories', false],
        ['GET', 'blog/categories/{id}', 'api/v1/blog/categories/{id}', false],
        ['POST', 'blog/categories', 'api/v1/blog/categories', true],
        ['PUT', 'blog/categories/{id}', 'api/v1/blog/categories/{id}', true],
        ['DELETE', 'blog/categories/{id}', 'api/v1/blog/categories/{id}', true],

        // Blog posts (S4b, growth-service v0.39.0 S2b): list (?status, ?category, ?q pass through), readiness check,
        // show, create, update (the revision guard answers a conflict), delete. Reads and the check carry no actor.
        ['GET', 'blog/posts', 'api/v1/blog/posts', false],
        ['POST', 'blog/posts', 'api/v1/blog/posts', true],
        ['POST', 'blog/posts/readiness', 'api/v1/blog/posts/readiness', false],
        ['GET', 'blog/posts/{id}', 'api/v1/blog/posts/{id}', false],
        ['PUT', 'blog/posts/{id}', 'api/v1/blog/posts/{id}', true],
        ['DELETE', 'blog/posts/{id}', 'api/v1/blog/posts/{id}', true],
        // S2c-1 (growth-service v0.41.0): publish now or at a time (optional `at` passes through), and unpublish.
        ['POST', 'blog/posts/{id}/publish', 'api/v1/blog/posts/{id}/publish', true],
        ['POST', 'blog/posts/{id}/unpublish', 'api/v1/blog/posts/{id}/unpublish', true],

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
        // G5c3a: announcements are written, targeted, scheduled, published and ended on the service; the product
        // delivers them in its own app. `estimate` asks the product for the reach (its users, not subscribers).
        ['GET', 'announcements', 'api/v1/announcements', false],
        ['POST', 'announcements', 'api/v1/announcements', true],
        ['POST', 'announcements/estimate', 'api/v1/announcements/estimate', false],
        ['PUT', 'announcements/{uuid}', 'api/v1/announcements/{uuid}', true],
        ['POST', 'announcements/{uuid}/publish', 'api/v1/announcements/{uuid}/publish', true],
        ['POST', 'announcements/{uuid}/schedule', 'api/v1/announcements/{uuid}/schedule', true],
        ['POST', 'announcements/{uuid}/end', 'api/v1/announcements/{uuid}/end', true],
        // G5d1: newsletters and alerts on the service. The delivery report and the sender stay server-side.
        ['GET', 'campaigns', 'api/v1/campaigns', false],
        ['POST', 'campaigns', 'api/v1/campaigns', true],
        ['POST', 'campaigns/estimate', 'api/v1/campaigns/estimate', false],
        // G5d5: a body drafted from the subject, on the admin's click (an AI call billed to the product: who asked is kept).
        // ⚠ Its own 90-second timeout: a model writing an email takes longer than the default 10, and the test box's
        // first draft came back as a 503 "unreachable" while the service was still writing it (0.13.1).
        ['POST', 'campaigns/draft', 'api/v1/campaigns/draft', true, 90],
        ['GET', 'campaigns/{uuid}', 'api/v1/campaigns/{uuid}', false],
        ['PUT', 'campaigns/{uuid}', 'api/v1/campaigns/{uuid}', true],
        ['DELETE', 'campaigns/{uuid}', 'api/v1/campaigns/{uuid}', true],
        ['GET', 'campaigns/{uuid}/checks', 'api/v1/campaigns/{uuid}/checks', false],
        ['POST', 'campaigns/{uuid}/schedule', 'api/v1/campaigns/{uuid}/schedule', true],
        ['POST', 'campaigns/{uuid}/send', 'api/v1/campaigns/{uuid}/send', true],
        ['POST', 'campaigns/{uuid}/cancel', 'api/v1/campaigns/{uuid}/cancel', true],
        ['POST', 'campaigns/{uuid}/test', 'api/v1/campaigns/{uuid}/test', true],
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
            // G5d2: opens and clicks on mail the service sent; an image and a redirect, so their own controller.
            Route::get('track/{token}/open', [GrowthTrackController::class, 'open'])->where('token', '[A-Za-z0-9]{32}')->name('growth-public.track.open');
            Route::get('track/{token}/click', [GrowthTrackController::class, 'click'])->where('token', '[A-Za-z0-9]{32}')->name('growth-public.track.click');
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
        // B3: who may be named as a post's reviewer (licensed, opted in; the product fails closed).
        Route::post(rtrim($uri, '/').'/reviewers', ReviewersController::class)->name('webhooks.growth-service.reviewers');
        // S4a (blog-on-growth-service): a published post into the product's knowledge, and its public pages refreshed.
        Route::post(rtrim($uri, '/').'/knowledge/upsert', [KnowledgeSinkController::class, 'upsert'])->name('webhooks.growth-service.knowledge.upsert');
        Route::post(rtrim($uri, '/').'/knowledge/remove', [KnowledgeSinkController::class, 'remove'])->name('webhooks.growth-service.knowledge.remove');
        Route::post(rtrim($uri, '/').'/revalidate', RevalidateController::class)->name('webhooks.growth-service.revalidate');
        // S4: the product's extra ADVISORY readiness checks for a post (never a lock; fail-open).
        Route::post(rtrim($uri, '/').'/posts/checks', PostChecksController::class)->name('webhooks.growth-service.posts.checks');
        // G4d1: the product's people groups (catalogue, expand to email hashes, search), signed like facts.
        Route::post(rtrim($uri, '/').'/segments', [SegmentsController::class, 'catalog'])->name('webhooks.growth-service.segments');
        Route::post(rtrim($uri, '/').'/segments/expand', [SegmentsController::class, 'expand'])->name('webhooks.growth-service.segments.expand');
        Route::post(rtrim($uri, '/').'/segments/search', [SegmentsController::class, 'search'])->name('webhooks.growth-service.segments.search');
        // G5c3a: how many of the product's own users an announcement would reach (only the product can count them).
        Route::post(rtrim($uri, '/').'/announcements/reach', AnnouncementReachController::class)->name('webhooks.growth-service.announcements.reach');

        return Route::post($uri, WebhookController::class)->name('webhooks.growth-service');
    }
}
