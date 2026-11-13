<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Laravel\Http\Concerns\VerifiesServiceRead;
use Shirahcan\GrowthClient\Plan\PlanSourcesAdapter;

/**
 * Answers growth-service's signed "what should we plan from?" call (G3d3) from the product's
 * PlanSourcesAdapter. Body `{month: "YYYY-MM"}`. Answers 200 `{data}`, 401, 422, 503 not_available, 500.
 */
class PlanSourcesController extends Controller
{
    use VerifiesServiceRead;

    public function __invoke(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'plan sources')) {
            return $refused;
        }

        $body = json_decode($request->getContent(), true);
        $month = is_array($body) && is_string($body['month'] ?? null) ? $body['month'] : '';
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return response()->json(['success' => false, 'message' => 'month must be YYYY-MM'], 422);
        }

        try {
            $adapter = $this->adapterFor(PlanSourcesAdapter::class, 'growth-client.plan_sources');
            if ($adapter === null) {
                return $this->notAvailable('This product does not share any plan sources.');
            }
            $data = $adapter->planSources($month);
        } catch (FactsUnavailable $e) {
            return $this->notAvailable($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'server_error', 'message' => 'The product could not read its plan sources.'], 500);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
