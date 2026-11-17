<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Laravel\Http\Concerns\VerifiesServiceRead;
use Shirahcan\GrowthClient\Reviewers\ReviewersAdapter;

/**
 * Answers growth-service's signed "who may be named as a reviewer?" call (B3) from the product's ReviewersAdapter.
 * Answers 200 `{data}`, 401, 503 not_available, 500.
 */
class ReviewersController extends Controller
{
    use VerifiesServiceRead;

    public function __invoke(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'reviewers')) {
            return $refused;
        }

        try {
            $adapter = $this->adapterFor(ReviewersAdapter::class, 'growth-client.reviewers');
            if ($adapter === null) {
                return $this->notAvailable('This product does not name any reviewers.');
            }
            $data = $adapter->reviewers();
        } catch (FactsUnavailable $e) {
            return $this->notAvailable($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'server_error', 'message' => 'The product could not read its reviewers.'], 500);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
