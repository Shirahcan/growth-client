<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shirahcan\GrowthClient\Exceptions\EstateKeyNotConfigured;
use Shirahcan\GrowthClient\Exceptions\GrowthServiceUnavailable;
use Shirahcan\GrowthClient\GrowthServiceClient;
use Shirahcan\GrowthClient\Laravel\GrowthActorResolver;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;

/**
 * Forwards one whitelisted admin call to growth-service (see {@see GrowthRoutes}).
 *
 * source: ai-client `ConsoleProxyController`. The service's own status and message come back unchanged,
 * so a 422 lands beside its field. Only three answers are the kit's own (F1 rule 8):
 * - `?as_product=` with no estate key: 403, said plainly, the service is never called;
 * - the service is unreachable: 503, reported;
 * - the service answered 5xx: 502 with a plain sentence, reported (its text is not for a browser).
 */
final class GrowthProxyController
{
    /** The service's narrowest actor columns; longer values are cut, never rejected. */
    private const ACTOR_REF_MAX = 64;

    private const ACTOR_NAME_MAX = 120;

    public function __invoke(Request $request, GrowthServiceClient $client, GrowthActorResolver $actor): JsonResponse
    {
        $row = GrowthRoutes::TABLE[(int) $request->route()->defaults['_growth_row']];

        return $this->forRow($request, $row, $client, $actor, array_filter($request->route()->parameters(), 'is_string'));
    }

    /**
     * One table row's forwarding rules (the seam tests drive with a synthetic write row until real
     * write rows arrive in G3+).
     *
     * @param  array{0: string, 1: string, 2: string, 3: bool, 4?: int}  $row  4: this row's own timeout in seconds (a slow row)
     * @param  array<string, string>  $params
     */
    public function forRow(Request $request, array $row, GrowthServiceClient $client, GrowthActorResolver $actor, array $params = []): JsonResponse
    {
        [$method, , $upstream, $withActor] = $row;

        $query = $request->query();
        $actsFor = $query[GrowthRoutes::AS_PRODUCT] ?? null;
        $estate = is_string($actsFor) && $actsFor !== '';

        $body = $method === 'GET' ? [] : self::sentBody($request);
        unset($body['actor']); // ⚠ never the browser's
        if ($withActor) {
            $who = $actor->resolve($request);
            $body['actor'] = [
                'ref' => mb_substr((string) $who['ref'], 0, self::ACTOR_REF_MAX),
                'name' => mb_substr((string) $who['name'], 0, self::ACTOR_NAME_MAX),
            ];
        }

        $path = $upstream;
        foreach ($params as $name => $value) {
            $path = str_replace('{'.$name.'}', rawurlencode($value), $path);
        }

        try {
            $result = $client->forward($method, $path, $query, $body, $estate, isset($row[4]) ? (int) $row[4] : null);
        } catch (EstateKeyNotConfigured $e) {
            return new JsonResponse(['error' => $e->errorCode, 'message' => $e->userMessage()], 403);
        } catch (GrowthServiceUnavailable $e) {
            report($e);

            return new JsonResponse(['error' => 'growth_unreachable', 'message' => $e->userMessage()], 503);
        }

        if ($result['status'] >= 500) {
            report(new \RuntimeException("growth-service proxy: {$method} {$path} answered {$result['status']}"));

            return new JsonResponse(['error' => 'growth_error', 'message' => 'Growth failed to answer that. It has been reported.'], 502);
        }

        return new JsonResponse($result['body'], $result['status']);
    }

    /**
     * The JSON body exactly as the browser sent it (0.33.1, 2026-11-23).
     *
     * ⚠ NEVER `$request->json()`: the product's global TrimStrings and ConvertEmptyStringsToNull have already rewritten
     * it, so a design's empty word ("") reached the service as null and its preview failed the PML schema ('"value" must
     * be a string'; Portify's TEST canvas then drew every section as its name only). growth-service cleans its own input
     * and leaves a design as sent (its DesignBody), so the proxy forwards what it was given.
     *
     * @return array<string, mixed>
     */
    private static function sentBody(Request $request): array
    {
        $raw = (string) $request->getContent();
        $decoded = $raw === '' ? null : json_decode($raw, true);

        return is_array($decoded) ? $decoded : $request->json()->all();
    }
}
