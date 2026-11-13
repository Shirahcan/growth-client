<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Laravel\Http\Concerns\VerifiesServiceRead;
use Shirahcan\GrowthClient\Segments\PlatformSegmentsAdapter;

/**
 * Answers growth-service's signed questions about the product's own people (G4d1) from the product's
 * PlatformSegmentsAdapter. Each answers 200 `{data}`, 401, 422, 503 not_available, or 500.
 *
 * ⚠ Unbound adapter = 503 "not available", NEVER an empty list: an empty group would read as "nobody",
 * and a picker that says 0 when the truth is "unknown" is the silent-default defect.
 */
class SegmentsController extends Controller
{
    use VerifiesServiceRead;

    /** The most tokens one expand may carry, and the most people one search returns. */
    public const MAX_TOKENS = 500;

    public const MAX_SEARCH = 25;

    public function catalog(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'people groups')) {
            return $refused;
        }

        return $this->answer(fn (PlatformSegmentsAdapter $a) => ['segments' => $a->catalog()]);
    }

    public function expand(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'people groups')) {
            return $refused;
        }
        $tokens = $this->body($request)['tokens'] ?? null;
        if (! is_array($tokens) || ! array_is_list($tokens) || count($tokens) > self::MAX_TOKENS) {
            return $this->invalid('tokens must be a list of at most '.self::MAX_TOKENS.' picks');
        }
        $clean = [];
        foreach ($tokens as $t) {
            $type = is_array($t) ? ($t['type'] ?? null) : null;
            if ($type === 'segment' && is_string($t['segment'] ?? null)) {
                $clean[] = ['type' => 'segment', 'segment' => $t['segment']];
            } elseif ($type === 'user' && is_string($t['user_type'] ?? null) && is_scalar($t['user_id'] ?? null)) {
                $clean[] = ['type' => 'user', 'user_type' => $t['user_type'], 'user_id' => (string) $t['user_id']];
            } else {
                return $this->invalid('each pick is a segment or a user');
            }
        }

        return $this->answer(fn (PlatformSegmentsAdapter $a) => $a->expand($clean));
    }

    public function search(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'people groups')) {
            return $refused;
        }
        $body = $this->body($request);
        $query = is_string($body['q'] ?? null) ? trim($body['q']) : '';
        if (mb_strlen($query) < 2 || mb_strlen($query) > 100) {
            return $this->invalid('q must be 2 to 100 characters');
        }
        $limit = max(1, min(self::MAX_SEARCH, (int) ($body['limit'] ?? 10)));

        return $this->answer(fn (PlatformSegmentsAdapter $a) => ['people' => $a->search($query, $limit)]);
    }

    /** The signature is checked by the caller first. @param callable(PlatformSegmentsAdapter): array<string, mixed> $read */
    private function answer(callable $read): JsonResponse
    {
        try {
            $adapter = $this->adapterFor(PlatformSegmentsAdapter::class, 'growth-client.segments');
            if ($adapter === null) {
                return $this->notAvailable('This product does not share its people groups.');
            }
            $data = $read($adapter);
        } catch (FactsUnavailable $e) {
            return $this->notAvailable($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'server_error', 'message' => 'The product could not read its people groups.'], 500);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $body = json_decode($request->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }
}
