<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Laravel\Http\Concerns\VerifiesServiceRead;
use Shirahcan\GrowthClient\Posts\PostChecksAdapter;

/**
 * growth-service's signed "any extra checks for this post?" call (blog-on-growth-service S4), answered by the product's
 * PostChecksAdapter. Body `{post}`; the answer is `{checks: [...]}` with every item normalised here (a malformed item
 * is dropped, never passed on), so every product answers in the same shape. Answers 200 `{data}`, 401, 422, 503, 500.
 */
class PostChecksController extends Controller
{
    use VerifiesServiceRead;

    public function __invoke(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'post checks')) {
            return $refused;
        }

        $body = json_decode($request->getContent(), true);
        $post = is_array($body) && is_array($body['post'] ?? null) ? $body['post'] : null;
        if ($post === null || ! is_string($post['id'] ?? null) || trim($post['id']) === '') {
            return response()->json(['success' => false, 'message' => 'post with an id is required'], 422);
        }

        try {
            $adapter = $this->adapterFor(PostChecksAdapter::class, 'growth-client.post_checks');
            if ($adapter === null) {
                return $this->notAvailable('This product adds no checks to posts.');
            }
            $checks = $adapter->checks($post);
        } catch (FactsUnavailable $e) {
            return $this->notAvailable($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'server_error', 'message' => 'The product could not check the post.'], 500);
        }

        return response()->json(['success' => true, 'data' => ['checks' => self::normalise($checks)]]);
    }

    /**
     * @param  mixed  $checks
     * @return list<array{key: string, label: string, hint: string|null, ok: bool, required: bool, weight: int}>
     */
    private static function normalise(mixed $checks): array
    {
        $out = [];
        foreach (is_array($checks) ? $checks : [] as $c) {
            $key = is_array($c) && is_string($c['key'] ?? null) ? trim($c['key']) : '';
            $label = is_array($c) && is_string($c['label'] ?? null) ? trim($c['label']) : '';
            if ($key === '' || $label === '' || ! is_bool($c['ok'] ?? null)) {
                continue;
            }
            $hint = is_string($c['hint'] ?? null) && trim($c['hint']) !== '' ? trim($c['hint']) : null;
            $out[] = [
                'key' => $key,
                'label' => $label,
                'hint' => $hint,
                'ok' => $c['ok'],
                'required' => (bool) ($c['required'] ?? false),
                'weight' => is_int($c['weight'] ?? null) ? max(0, $c['weight']) : 0,
            ];
        }

        return $out;
    }
}
