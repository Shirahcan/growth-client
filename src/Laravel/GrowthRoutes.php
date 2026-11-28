<?php

namespace Shirahcan\GrowthClient\Laravel;

use Illuminate\Routing\Route as RouteObject;
use Illuminate\Support\Facades\Route;
use Shirahcan\GrowthClient\Laravel\Http\GrowthProxyController;
use Shirahcan\GrowthClient\Laravel\Http\GrowthPublicProxyController;
use Shirahcan\GrowthClient\Laravel\Http\GrowthTrackController;
use Shirahcan\GrowthClient\Laravel\Http\AnnouncementReachController;
use Shirahcan\GrowthClient\Laravel\Http\ConversionsController;
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
        // (0.35.1) "Suggest more" writes ideas with a model: its own 90 seconds, like the other model rows. At the default
        // 10 it read as "Growth is briefly unavailable" while the service was still writing (Porter's operation card,
        // 2026-11-25).
        ['POST', 'plan/ideas/more', 'api/v1/plan/ideas/more', true, 90],
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
        // (0.35.1) Checking reads the watched page itself, which growth-service allows 20 seconds: 45 here, so the proxy never
        // gives up first and reports "unavailable" for a check that finished.
        ['POST', 'watched-sources/{id}/check', 'api/v1/watched-sources/{id}/check', true, 45],

        // The radar's settings (G3e5): on/off, tracked countries, thresholds, cadence.
        ['GET', 'settings/radar', 'api/v1/settings/radar', false],
        ['PUT', 'settings/radar', 'api/v1/settings/radar', true],

        // Results (G6a4): the numbers a Results tab shows, and the AI host list views are classified with.
        ['GET', 'results', 'api/v1/results', false],
        ['GET', 'results/posts', 'api/v1/results/posts', false],
        // G6c4: what led to paid work (first and last touch, counts only).
        ['GET', 'results/attribution', 'api/v1/results/attribution', false],
        // G6d: AI answer visibility; the check and the questions carry the admin who asked.
        ['GET', 'results/ai-visibility', 'api/v1/results/ai-visibility', false],
        ['POST', 'results/ai-visibility/check', 'api/v1/results/ai-visibility/check', true],
        ['PUT', 'settings/ai-visibility', 'api/v1/settings/ai-visibility', true],
        ['GET', 'settings/results', 'api/v1/settings/results', false],
        ['PUT', 'settings/results', 'api/v1/settings/results', true],

        // Landing pages and keyword links (G7b3): the admin screens; every write names the person.
        ['GET', 'landing-pages', 'api/v1/landing-pages', false],
        ['POST', 'landing-pages', 'api/v1/landing-pages', true],
        // G7d: a landing page's words from the person's description (shared.growth.landing_copy).
        ['POST', 'landing-pages/draft-copy', 'api/v1/landing-pages/draft-copy', true],
        // G7d2: the screen polls an on-demand writer's run (AI never inside a request).
        ['GET', 'writer-runs/{uuid}', 'api/v1/writer-runs/{uuid}', false],
        // G7e4: a post's or landing page's share card as the admin sees it, and "Draw it again" (queues the job).
        ['GET', 'cards/{kind}/{uuid}', 'api/v1/cards/{kind}/{uuid}', false],
        ['POST', 'cards/{kind}/{uuid}/redraw', 'api/v1/cards/{kind}/{uuid}/redraw', true],
        ['GET', 'landing-pages/{uuid}', 'api/v1/landing-pages/{uuid}', false],
        ['PUT', 'landing-pages/{uuid}', 'api/v1/landing-pages/{uuid}', true],
        ['DELETE', 'landing-pages/{uuid}', 'api/v1/landing-pages/{uuid}', true],
        ['POST', 'landing-pages/{uuid}/publish', 'api/v1/landing-pages/{uuid}/publish', true],
        ['POST', 'landing-pages/{uuid}/pause', 'api/v1/landing-pages/{uuid}/pause', true],
        // G7b4a: a designed landing page's design, for the shared page editor (the posts designer's shapes).
        ['GET', 'landing-pages/{uuid}/document', 'api/v1/landing-pages/{uuid}/document', false],
        ['PUT', 'landing-pages/{uuid}/document', 'api/v1/landing-pages/{uuid}/document', true],
        ['PUT', 'landing-pages/{uuid}/document/settings', 'api/v1/landing-pages/{uuid}/document/settings', true],
        ['POST', 'landing-pages/{uuid}/document/preview', 'api/v1/landing-pages/{uuid}/document/preview', false],
        ['POST', 'landing-pages/{uuid}/document/check', 'api/v1/landing-pages/{uuid}/document/check', false],
        ['POST', 'landing-pages/{uuid}/document/publish', 'api/v1/landing-pages/{uuid}/document/publish', true],
        ['DELETE', 'landing-pages/{uuid}/document/draft', 'api/v1/landing-pages/{uuid}/document/draft', true],
        // G7h-G7k: a landing page's several pages, a child page's design, and what the pages share (after the form, dates).
        // ⚠ pages/order before pages/{page}: the first route that matches answers.
        ['GET', 'landing-pages/{uuid}/pages', 'api/v1/landing-pages/{uuid}/pages', false],
        ['POST', 'landing-pages/{uuid}/pages', 'api/v1/landing-pages/{uuid}/pages', true],
        ['PUT', 'landing-pages/{uuid}/pages/order', 'api/v1/landing-pages/{uuid}/pages/order', true],
        ['PUT', 'landing-pages/{uuid}/pages/{page}', 'api/v1/landing-pages/{uuid}/pages/{page}', true],
        ['DELETE', 'landing-pages/{uuid}/pages/{page}', 'api/v1/landing-pages/{uuid}/pages/{page}', true],
        ['GET', 'landing-pages/{uuid}/pages/{page}/document', 'api/v1/landing-pages/{uuid}/pages/{page}/document', false],
        ['PUT', 'landing-pages/{uuid}/pages/{page}/document', 'api/v1/landing-pages/{uuid}/pages/{page}/document', true],
        ['PUT', 'landing-pages/{uuid}/pages/{page}/document/settings', 'api/v1/landing-pages/{uuid}/pages/{page}/document/settings', true],
        ['POST', 'landing-pages/{uuid}/pages/{page}/document/preview', 'api/v1/landing-pages/{uuid}/pages/{page}/document/preview', false],
        ['POST', 'landing-pages/{uuid}/pages/{page}/document/check', 'api/v1/landing-pages/{uuid}/pages/{page}/document/check', false],
        ['POST', 'landing-pages/{uuid}/pages/{page}/document/publish', 'api/v1/landing-pages/{uuid}/pages/{page}/document/publish', true],
        ['DELETE', 'landing-pages/{uuid}/pages/{page}/document/draft', 'api/v1/landing-pages/{uuid}/pages/{page}/document/draft', true],
        ['PUT', 'landing-pages/{uuid}/set', 'api/v1/landing-pages/{uuid}/set', true],
        ['GET', 'keyword-links', 'api/v1/keyword-links', false],
        ['POST', 'keyword-links', 'api/v1/keyword-links', true],
        ['PUT', 'keyword-links/{uuid}', 'api/v1/keyword-links/{uuid}', true],
        ['DELETE', 'keyword-links/{uuid}', 'api/v1/keyword-links/{uuid}', true],
        ['GET', 'settings/landing', 'api/v1/settings/landing', false],
        ['PUT', 'settings/landing', 'api/v1/settings/landing', true],

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

        // The product's Growth picture library (public images plan, P3). Adding one is NOT a row: the file goes from
        // the product's server through GrowthClient::uploadMedia(), never through this JSON proxy.
        ['GET', 'media', 'api/v1/media', false],
        ['GET', 'media/{id}', 'api/v1/media/{id}', false],
        ['PATCH', 'media/{id}', 'api/v1/media/{id}', true],
        ['DELETE', 'media/{id}', 'api/v1/media/{id}', true],

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
        // Growth templates (growth-service, 2026-11-20): create (blank or a copy), retire and restore (B1); a template
        // made from a post or a newsletter (B2); a template opened and saved like a document (B3); the template as a
        // reader would see a post or email made from it (B4).
        ['POST', 'layouts', 'api/v1/layouts', true],
        ['POST', 'layouts/{key}/retire', 'api/v1/layouts/{key}/retire', true],
        ['POST', 'layouts/{key}/restore', 'api/v1/layouts/{key}/restore', true],
        ['POST', 'layouts/from-post/{post}', 'api/v1/layouts/from-post/{post}', true],
        ['POST', 'layouts/from-campaign/{campaign}', 'api/v1/layouts/from-campaign/{campaign}', true],
        ['GET', 'layouts/{key}/document', 'api/v1/layouts/{key}/document', false],
        ['PUT', 'layouts/{key}/document', 'api/v1/layouts/{key}/document', true],
        ['GET', 'layouts/{key}/reader-preview', 'api/v1/layouts/{key}/reader-preview', false],
        // Growth templates E3: edits wait in a draft; "Save for new posts" (publish) makes the next version, and posts
        // keep the version they started from. Details: the name and each part's label, need and count.
        ['POST', 'layouts/{key}/document/preview', 'api/v1/layouts/{key}/document/preview', false],
        ['POST', 'layouts/{key}/document/check', 'api/v1/layouts/{key}/document/check', false],
        ['POST', 'layouts/{key}/document/publish', 'api/v1/layouts/{key}/document/publish', true],
        ['DELETE', 'layouts/{key}/document/draft', 'api/v1/layouts/{key}/document/draft', true],
        ['GET', 'layouts/{key}/document/fields', 'api/v1/layouts/{key}/document/fields', false],
        ['PUT', 'layouts/{key}/document/fields/{name}', 'api/v1/layouts/{key}/document/fields/{name}', true],

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
        // The post list's Score column (owner 2026-11-18): each listed post's search score, a read (0.30.1).
        ['POST', 'blog/posts/scores', 'api/v1/blog/posts/scores', false],
        ['GET', 'blog/posts/{id}', 'api/v1/blog/posts/{id}', false],
        ['PUT', 'blog/posts/{id}', 'api/v1/blog/posts/{id}', true],
        ['DELETE', 'blog/posts/{id}', 'api/v1/blog/posts/{id}', true],
        // S2c-1 (growth-service v0.41.0): publish now or at a time (optional `at` passes through), and unpublish.
        ['POST', 'blog/posts/{id}/publish', 'api/v1/blog/posts/{id}/publish', true],
        ['POST', 'blog/posts/{id}/unpublish', 'api/v1/blog/posts/{id}/unpublish', true],
        // The designed post as a document for the shared page editor (growth-service v0.42.0, S3c). Reads, previews and
        // checks carry no actor; saving, publishing and discarding the draft carry one.
        ['GET', 'blog/posts/{id}/document', 'api/v1/blog/posts/{id}/document', false],
        ['PUT', 'blog/posts/{id}/document', 'api/v1/blog/posts/{id}/document', true],
        ['POST', 'blog/posts/{id}/document/preview', 'api/v1/blog/posts/{id}/document/preview', false],
        ['POST', 'blog/posts/{id}/document/check', 'api/v1/blog/posts/{id}/document/check', false],
        ['POST', 'blog/posts/{id}/document/publish', 'api/v1/blog/posts/{id}/document/publish', true],
        ['DELETE', 'blog/posts/{id}/document/draft', 'api/v1/blog/posts/{id}/document/draft', true],
        // The designer's post details panel (growth-service v0.43.0, S3c part 2): read every field, save one.
        ['GET', 'blog/posts/{id}/document/fields', 'api/v1/blog/posts/{id}/document/fields', false],
        ['PUT', 'blog/posts/{id}/document/fields/{name}', 'api/v1/blog/posts/{id}/document/fields/{name}', true],
        // The designer's SEO slide-in (growth-service v0.55.0, T2a): the score, the grouped checks and the search-result
        // preview over the post's current draft. A read: no actor.
        ['GET', 'blog/posts/{id}/document/seo', 'api/v1/blog/posts/{id}/document/seo', false],
        // A designed post's earlier versions (growth-service, close-multi-pass-gaps P7b): the Versions panel's list (a read)
        // and putting one back in the DRAFT (never live; the actor is recorded, the revision guard answers a conflict).
        ['GET', 'blog/posts/{id}/versions', 'api/v1/blog/posts/{id}/versions', false],
        ['POST', 'blog/posts/{id}/versions/{version}/restore', 'api/v1/blog/posts/{id}/versions/{version}/restore', true],
        // One version beside the editor's design (growth-service v0.61.0, versions-compare P2a): the editor sends what it
        // holds, nothing is saved, so no actor.
        ['POST', 'blog/posts/{id}/versions/{version}/compare', 'api/v1/blog/posts/{id}/versions/{version}/compare', false],
        // S2d (growth-service v0.49.0): the LinkedIn version and "Check the writing", each ONE model call on the admin's
        // click (billed to the product: who asked is kept). Their own 90-second timeout, like campaigns/draft: a model
        // writing takes longer than the default 10, and a timeout here would read as "unreachable" while it still works.
        ['POST', 'blog/posts/{id}/linkedin', 'api/v1/blog/posts/{id}/linkedin', true, 90],
        ['POST', 'blog/posts/{id}/proofread', 'api/v1/blog/posts/{id}/proofread', true, 90],
        // A post written in the plain editor becomes a design (growth-service v0.53.0, pml HtmlToPml): no model call, so
        // the default timeout. Body {dry_run?: bool}; the actor is recorded.
        ['POST', 'blog/posts/{id}/convert', 'api/v1/blog/posts/{id}/convert', true],
        // (0.27.0) The background prepare's state row is gone with the background prepare itself: an upload goes through
        // the post's Porter console. Preparing stays on the product's own server (it reads the file), never a browser row.

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
        // A designed newsletter in the shared email editor (growth-service N1b): the post document's rows, for a campaign.
        // Reads and previews carry no actor; a save does. A campaign starts designed with `layout_key` on POST campaigns.
        ['GET', 'campaigns/{uuid}/document', 'api/v1/campaigns/{uuid}/document', false],
        ['PUT', 'campaigns/{uuid}/document', 'api/v1/campaigns/{uuid}/document', true],
        ['POST', 'campaigns/{uuid}/document/preview', 'api/v1/campaigns/{uuid}/document/preview', false],
        ['POST', 'campaigns/{uuid}/document/check', 'api/v1/campaigns/{uuid}/document/check', false],
        // The email exactly as a reader receives it now ({html, text, subject}), for the editor's "As sent" view. A read.
        ['GET', 'campaigns/{uuid}/document/sent', 'api/v1/campaigns/{uuid}/document/sent', false],
        // Deliverability health (G5i): Settings' panel; saving names the person.
        ['GET', 'deliverability', 'api/v1/deliverability', false],
        ['PUT', 'settings/deliverability', 'api/v1/settings/deliverability', true],
        // Welcome series (G5g): built on the Newsletter tab; every write names the person.
        ['GET', 'welcome-series', 'api/v1/welcome-series', false],
        ['POST', 'welcome-series', 'api/v1/welcome-series', true],
        ['PUT', 'welcome-series/{uuid}', 'api/v1/welcome-series/{uuid}', true],
        ['DELETE', 'welcome-series/{uuid}', 'api/v1/welcome-series/{uuid}', true],
        ['GET', 'campaigns/{uuid}/document/fields', 'api/v1/campaigns/{uuid}/document/fields', false],
        ['PUT', 'campaigns/{uuid}/document/fields/{name}', 'api/v1/campaigns/{uuid}/document/fields/{name}', true],
        // Prepare an issue inside its template from the team's notes, the product's figures and news (growth-service N1g):
        // the writer may take up to 100 s there, so this row waits 120. Body {text?, apply?, revision?, fields_version?,
        // document?}; a document's text is read by the product's own server, never sent from a browser as a file.
        ['POST', 'campaigns/{uuid}/prepare', 'api/v1/campaigns/{uuid}/prepare', true, 120],
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
        // G7e3: a growth document's social card, as the product's share-card page draws it. Answered only for the
        // 10-minute signed link the service's card job made; the signature travels in the query, which is forwarded.
        ['GET', 'cards/{kind}/{uuid}', 'api/v1/public/cards/{kind}/{uuid}', false],
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
        // G6a2: when the service's subscribers last became the product's customers.
        Route::post(rtrim($uri, '/').'/conversions', ConversionsController::class)->name('webhooks.growth-service.conversions');
        Route::post(rtrim($uri, '/').'/paid-work', \Shirahcan\GrowthClient\Laravel\Http\PaidWorkController::class)->name('webhooks.growth-service.paid-work');

        return Route::post($uri, WebhookController::class)->name('webhooks.growth-service');
    }
}
