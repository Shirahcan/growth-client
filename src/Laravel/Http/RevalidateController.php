<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Laravel\Http\Concerns\VerifiesServiceRead;
use Shirahcan\GrowthClient\Pages\RevalidateAdapter;

/**
 * growth-service's signed "what readers see changed" call (blog-on-growth-service S4a), answered by the product's
 * RevalidateAdapter. Body `{event, slug, previous_slug?, category_slugs?}`; the change is normalised here so every
 * product receives the same shape. Answers 200 `{data}`, 401, 422, 503 not_available, 500.
 */
class RevalidateController extends Controller
{
    use VerifiesServiceRead;

    public function __invoke(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'page revalidation')) {
            return $refused;
        }

        $body = json_decode($request->getContent(), true);
        $event = is_array($body) && is_string($body['event'] ?? null) ? $body['event'] : '';
        $slug = is_array($body) && is_string($body['slug'] ?? null) ? trim($body['slug']) : '';
        if (! in_array($event, RevalidateAdapter::EVENTS, true) || $slug === '') {
            return response()->json(['success' => false, 'message' => 'event ('.implode(', ', RevalidateAdapter::EVENTS).') and slug are required'], 422);
        }

        $previous = is_string($body['previous_slug'] ?? null) && trim($body['previous_slug']) !== '' ? trim($body['previous_slug']) : null;
        $categories = array_values(array_filter(
            is_array($body['category_slugs'] ?? null) ? $body['category_slugs'] : [],
            static fn ($s) => is_string($s) && trim($s) !== '',
        ));

        try {
            $adapter = $this->adapterFor(RevalidateAdapter::class, 'growth-client.revalidate');
            if ($adapter === null) {
                return $this->notAvailable('This product does not cache its public pages.');
            }
            $data = $adapter->revalidate([
                'event' => $event,
                'slug' => $slug,
                'previous_slug' => $previous,
                'category_slugs' => array_map('trim', $categories),
            ]);
        } catch (FactsUnavailable $e) {
            return $this->notAvailable($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'server_error', 'message' => 'The product could not refresh its pages.'], 500);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
