<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Laravel\Http\Concerns\VerifiesServiceRead;
use Shirahcan\GrowthClient\Segments\AnnouncementReachAdapter;

/**
 * Answers growth-service's signed "how many of your users would this announcement reach?" (G5c3a) from the product's
 * AnnouncementReachAdapter: 200 `{data: {count}}`, 401, 422, 503 not_available, or 500.
 */
class AnnouncementReachController extends Controller
{
    use VerifiesServiceRead;

    public const MAX_PICKS = 500;

    public function __invoke(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'announcement reach')) {
            return $refused;
        }
        $body = json_decode($request->getContent(), true);
        $target = is_array($body) && is_array($body['target'] ?? null) ? $body['target'] : null;
        $mode = $target['mode'] ?? null;
        if ($target === null || ! in_array($mode, ['all', 'specific'], true)) {
            return $this->invalid('target.mode must be all or specific');
        }
        $clean = ['mode' => $mode, 'include' => [], 'exclude' => []];
        foreach (['include', 'exclude'] as $side) {
            $picks = $target[$side] ?? [];
            if (! is_array($picks) || ! array_is_list($picks) || count($picks) > self::MAX_PICKS) {
                return $this->invalid("target.{$side} must be a list of at most ".self::MAX_PICKS.' picks');
            }
            foreach ($picks as $p) {
                $type = is_array($p) ? ($p['type'] ?? null) : null;
                if ($type === 'segment' && is_string($p['segment'] ?? null)) {
                    $clean[$side][] = ['type' => 'segment', 'segment' => $p['segment']];
                } elseif ($type === 'user' && is_string($p['user_type'] ?? null) && is_scalar($p['user_id'] ?? null)) {
                    $clean[$side][] = ['type' => 'user', 'user_type' => $p['user_type'], 'user_id' => (string) $p['user_id']];
                } else {
                    return $this->invalid('each pick is a segment or a user');
                }
            }
        }

        try {
            $adapter = $this->adapterFor(AnnouncementReachAdapter::class, 'growth-client.announcement_reach');
            if ($adapter === null) {
                return $this->notAvailable('This product does not count who its announcements reach.');
            }
            $count = $adapter->count($clean);
        } catch (FactsUnavailable $e) {
            return $this->notAvailable($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'server_error', 'message' => 'The product could not count who this announcement reaches.'], 500);
        }

        return response()->json(['success' => true, 'data' => ['count' => max(0, $count)]]);
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 422);
    }
}
