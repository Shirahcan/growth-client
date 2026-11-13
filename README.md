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
