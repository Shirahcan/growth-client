<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Knowledge\KnowledgeSinkAdapter;
use Shirahcan\GrowthClient\Laravel\Http\Concerns\VerifiesServiceRead;

/**
 * growth-service's signed knowledge calls (blog-on-growth-service S4a): `knowledge/upsert` with `{post}` and
 * `knowledge/remove` with `{id, reason}`, answered by the product's KnowledgeSinkAdapter. Only the stable keys are
 * checked here (the post's id); the post's other fields are the product's to read.
 * Answers 200 `{data}`, 401, 422, 503 not_available, 500.
 */
class KnowledgeSinkController extends Controller
{
    use VerifiesServiceRead;

    public function upsert(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'knowledge')) {
            return $refused;
        }

        $body = json_decode($request->getContent(), true);
        $post = is_array($body) && is_array($body['post'] ?? null) ? $body['post'] : null;
        if ($post === null || ! is_string($post['id'] ?? null) || trim($post['id']) === '') {
            return response()->json(['success' => false, 'message' => 'post with an id is required'], 422);
        }

        return $this->answer(fn (KnowledgeSinkAdapter $sink) => $sink->upsert($post));
    }

    public function remove(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'knowledge')) {
            return $refused;
        }

        $body = json_decode($request->getContent(), true);
        $id = is_array($body) && is_string($body['id'] ?? null) ? trim($body['id']) : '';
        if ($id === '') {
            return response()->json(['success' => false, 'message' => 'id is required'], 422);
        }
        $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';

        return $this->answer(fn (KnowledgeSinkAdapter $sink) => $sink->remove($id, $reason));
    }

    /** @param  callable(KnowledgeSinkAdapter): array<string, mixed>  $call */
    private function answer(callable $call): JsonResponse
    {
        try {
            $adapter = $this->adapterFor(KnowledgeSinkAdapter::class, 'growth-client.knowledge_sink');
            if ($adapter === null) {
                return $this->notAvailable('This product does not take posts into its knowledge.');
            }
            $data = $call($adapter);
        } catch (FactsUnavailable $e) {
            return $this->notAvailable($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'server_error', 'message' => 'The product could not update its knowledge.'], 500);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }
}
