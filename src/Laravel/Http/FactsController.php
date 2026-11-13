<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use RuntimeException;
use Shirahcan\GrowthClient\Facts\FactsAdapter;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\WebhookSignature;

/**
 * Answers growth-service's signed "what is the figure now?" calls (G3c), the same in every product.
 * Registered beside the event receiver by {@see \Shirahcan\GrowthClient\Laravel\GrowthRoutes::webhookRoute()}.
 *
 * Body: `{op: "feeds"}`, `{op: "figures", feed, scope?}` or `{op: "figure", key}`.
 * Answers: 200 `{data}`; 401 bad signature; 422 bad request; 503 `{error: "not_available", message}`
 * (the product cannot answer, in a plain sentence); 500 for a fault in the product's adapter.
 *
 * ⚠ Reachable from the internet like the event receiver, so the signature is the whole defence, and no
 * secret configured = refuse everything. The window is 5 minutes, not the receiver's 24 hours: a read
 * is answered at once and never redelivered, so an old signed request is only a replay.
 */
class FactsController extends Controller
{
    public const TOLERANCE_SECONDS = 300;

    private const OPS = ['feeds', 'figures', 'figure'];

    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('growth-client.callback_secret');
        $raw = $request->getContent();

        if ($secret === '' || ! WebhookSignature::verify($raw, $request->header('X-Growth-Signature'), $request->header('X-Growth-Timestamp'), $secret, null, self::TOLERANCE_SECONDS)) {
            report(new RuntimeException('growth-service facts call refused: '.($secret === '' ? 'GROWTH_SERVICE_CALLBACK_SECRET is not set' : 'signature mismatch or expired')));

            return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
        }

        $body = json_decode($raw, true);
        $op = is_array($body) ? (string) ($body['op'] ?? '') : '';
        $feed = is_array($body) && is_string($body['feed'] ?? null) ? trim($body['feed']) : '';
        $key = is_array($body) && is_string($body['key'] ?? null) ? trim($body['key']) : '';
        $scope = is_array($body) && is_array($body['scope'] ?? null) ? $body['scope'] : [];

        if (! in_array($op, self::OPS, true) || ($op === 'figures' && $feed === '') || ($op === 'figure' && $key === '')) {
            return response()->json(['success' => false, 'message' => 'op must be feeds, figures (with feed) or figure (with key)'], 422);
        }

        try {
            $facts = $this->adapter();
            if ($facts === null) {
                return $this->notAvailable('This product does not answer for any figures.');
            }
            $data = match ($op) {
                'feeds' => $facts->feeds(),
                'figures' => $facts->figures($feed, $scope),
                'figure' => $facts->figure($key),
            };
        } catch (FactsUnavailable $e) {
            return $this->notAvailable($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'server_error', 'message' => 'The product could not read its figures.'], 500);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * The product's adapter: a container binding of FactsAdapter wins, else the `growth-client.facts`
     * class. Resolved per call, so config set after boot (tests, config:cache) is honoured. Null = none.
     */
    private function adapter(): ?FactsAdapter
    {
        if (app()->bound(FactsAdapter::class)) {
            return app(FactsAdapter::class);
        }

        $class = config('growth-client.facts');
        if (! is_string($class) || $class === '') {
            return null;
        }

        $adapter = app($class);
        if (! $adapter instanceof FactsAdapter) {
            throw new RuntimeException("growth-client.facts [{$class}] does not implement FactsAdapter.");
        }

        return $adapter;
    }

    private function notAvailable(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'error' => 'not_available', 'message' => $message], 503);
    }
}
