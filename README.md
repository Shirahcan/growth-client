# shirahcan/growth-client

The product-side kit for the Shirah estate's **growth-service**: a typed client, a fake that keeps the
service's contract, and (from G2b) the proxy routes a product mounts so its admin screens reach the
service with the actor taken from the product's own session.

**Plan:** `portify/docs/plans/shared-console-and-growth-2026-11-12/` (G2a onwards).
**Convention:** `portify/docs/architecture/SHARED_SERVICE_PRODUCT_SCOPING.md` (F1).

## Install

```bash
composer require shirahcan/growth-client
```

```dotenv
GROWTH_SERVICE_URL=http://127.0.0.1:8011     # loopback, always
GROWTH_SERVICE_TRUST_KEY=<issued by the service: php artisan growth:issue-key <product>>
```

Resolving `GrowthClient` with no key **throws** (a deployment mistake, found at once, not as a 401 in
front of an admin).

## Use

```php
$growth = app(\Shirahcan\GrowthClient\GrowthClient::class);
$growth->whoami();                       // ['product' => 'portify', 'acting_product' => null]
$growth->asProduct('mploynow')->whoami(); // estate key only (F1 rule 3); a product key gets `forbidden`
```

Every refusal is a `GrowthServiceException`:

| Class | When | `userMessage()` | `shouldReport()` |
|---|---|---|---|
| `GrowthServiceUnavailable` | unreachable, timeout, 5xx, 429 | "Growth is briefly unavailable..." | yes |
| `GrowthRequestRejected` | 422 validation, 409 conflict | the service's own sentence | no |
| `GrowthNotFound` | 404 (also another product's record) | "That item could not be found..." | no |
| `GrowthServiceException` | 401 key missing/revoked, 403 forbidden | a generic sentence | yes |

⚠ **It owns no Growth data.** No local copy "for outages": that would be a second store of subscribers
and consent. A down service fails closed.

## Test

```php
$fake = \Shirahcan\GrowthClient\Testing\FakeGrowth::forProduct('portify');   // or ::estate('portify')
app()->instance(\Shirahcan\GrowthClient\GrowthClient::class, $fake);
// ... then $fake->calls('whoami')
```

The fake refuses what the service refuses (same class, code and status), proven by
`FakeKeepsTheServicesContractTest` against the service's real answers.

## Proxy and callbacks (G2b)

```php
// inside the product's admin auth group, behind its own gate:
\Shirahcan\GrowthClient\Laravel\GrowthRoutes::mountAdmin('admin/growth-service');
// public, signed by the service:
\Shirahcan\GrowthClient\Laravel\GrowthRoutes::webhookRoute('/webhooks/growth-service');
```

- **A whitelist, never a pass-through** (`GrowthRoutes::TABLE`): a path not listed is 404 and never
  reaches the service. Rows arrive slice by slice with their endpoint.
- **The actor is the product's.** On a write row the proxy overwrites the body's `actor` with
  `GrowthActorResolver` (default: the signed-in user's id and email; bind your own in
  `growth-client.actor`). A browser-sent `actor` is dropped.
- **`?as_product=`** needs `GROWTH_SERVICE_ADMIN_KEY` (the shirah.co portal only); without it the proxy
  answers 403 itself and never calls the service.
- **Errors (F1 rule 8):** the service's 4xx comes back unchanged; a 5xx is reported and answered 502 with
  a plain sentence; unreachable is reported and answered 503.
- **Callbacks:** `X-Growth-Signature` + `X-Growth-Timestamp` (base64 HMAC-SHA256 of `"{ts}.{body}"`,
  24h replay window, the calendar-service scheme). No `GROWTH_SERVICE_CALLBACK_SECRET` = refuse all.
  Each `event_id` is recorded once in `growth_service_events` and announced once as
  `GrowthServiceEventReceived` (listen queued; call `markProcessed()` to drop the payload).

## Facts (G3c)

The service asks the product for its current official figures (Radar, post facts). Implement
`Facts\FactsAdapter` and name it in `growth-client.facts` (or bind `FactsAdapter` yourself):

```php
// config/growth-client.php (only the keys you set; the kit fills the rest)
return ['facts' => \App\Services\Growth\Adapters\MyFacts::class];
```

- `webhookRoute($uri)` also mounts `POST $uri/facts`: same signature and secret as the callbacks, but a
  **5-minute** window (a read is never redelivered). Body `{op: feeds}`, `{op: figures, feed, scope?}`
  or `{op: figure, key}`; answers `{data}`.
- Two kinds of feed: `events` (each figure is a happening; a new key is news) and `levels` (a figure
  moves; a moved `number` is news). A figure carries `key, label, value, number, group, as_of,
  source_label, source_url`.
- **Not available is an answer:** throw `FactsUnavailable` with a plain sentence (answered 503
  `not_available`). Never return an old value or an empty list to look healthy.

## Plan sources (G3d3)

What only the product knows that Growth plans from: implement `Plan\PlanSourcesAdapter` and name it in
`growth-client.plan_sources`. `webhookRoute($uri)` mounts `POST $uri/plan-sources` (same signature and
5-minute window as `/facts`), body `{month: "YYYY-MM"}`, answering `{product: {name, about}, sources:
[{key, kind: led_to_bookings|people_asking, label, detail, count, audience, refs}], published_titles,
gap_audiences: {assistant audience: Growth audience}}`. The service adds its Radar signals and ai-service's
unanswered questions itself.

## The public door (G4b)

`GrowthRoutes::mountPublic('growth')` mounts a visitor's own confirm, preferences and unsubscribe (the
token from their email), forwarded with the product's key. Wrap it in a per-visitor throttle. The
visitor's IP and browser are taken from the request (anything the form sent under those names is
dropped), and `?as_product=` never passes.

Sign-up is NOT a public row: its answer carries the confirm token. The product's server calls
`forward('POST', 'api/v1/audience/subscribe', [], [...])`, emails the confirmation itself (until
growth-service sends mail, G5b), and answers its visitor without the token.
