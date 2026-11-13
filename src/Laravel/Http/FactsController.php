<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\GrowthClient\Facts\FactsAdapter;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Laravel\Http\Concerns\VerifiesServiceRead;

/**
 * Answers growth-service's signed "what is the figure now?" calls (G3c), the same in every product.
 * Registered beside the event receiver by {@see \Shirahcan\GrowthClient\Laravel\GrowthRoutes::webhookRoute()}.
 *
 * Body: `{op: "feeds"}`, `{op: "figures", feed, scope?}` or `{op: "figure", key}`.
 * Answers: 200 `{data}`; 401 bad signature; 422 bad request; 503 `{error: "not_available", message}`
 * (the product cannot answer, in a plain sentence); 500 for a fault in the product's adapter.
 *
 * ⚠ The signature (5-minute window, no secret = refuse all) is {@see VerifiesServiceRead}, shared with
 * the plan-sources read.
 */
class FactsController extends Controller
{
    use VerifiesServiceRead;

    private const OPS = ['feeds', 'figures', 'figure'];

    public function __invoke(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'facts')) {
            return $refused;
        }

        $body = json_decode($request->getContent(), true);
        $op = is_array($body) ? (string) ($body['op'] ?? '') : '';
        $feed = is_array($body) && is_string($body['feed'] ?? null) ? trim($body['feed']) : '';
        $key = is_array($body) && is_string($body['key'] ?? null) ? trim($body['key']) : '';
        $scope = is_array($body) && is_array($body['scope'] ?? null) ? $body['scope'] : [];

        if (! in_array($op, self::OPS, true) || ($op === 'figures' && $feed === '') || ($op === 'figure' && $key === '')) {
            return response()->json(['success' => false, 'message' => 'op must be feeds, figures (with feed) or figure (with key)'], 422);
        }

        try {
            $facts = $this->adapterFor(FactsAdapter::class, 'growth-client.facts');
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
}
