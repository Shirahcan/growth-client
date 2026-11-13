<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shirahcan\GrowthClient\Exceptions\GrowthServiceUnavailable;
use Shirahcan\GrowthClient\GrowthServiceClient;
use Shirahcan\GrowthClient\Laravel\GrowthRoutes;

/**
 * The PUBLIC door (G4b): a visitor's confirm, preferences and unsubscribe, reached on the product's own
 * domain with the token from their email, forwarded with the product's key.
 *
 * ⚠ NO SIGN-UP HERE. Sign-up's answer carries the confirm token; the product's server calls it and
 * emails the confirmation itself (`GrowthServiceClient::forward('POST', 'api/v1/audience/subscribe', ...)`).
 *
 * Evidence rows get the visitor's IP and browser from THIS request; anything the browser sent under
 * those names, or as an actor, is dropped. Never `?as_product=`: a public caller acts for no one else.
 * Throttle the mount per visitor (the product wraps it, e.g. `throttle:30,1`).
 */
final class GrowthPublicProxyController
{
    public function __invoke(Request $request, GrowthServiceClient $client): JsonResponse
    {
        [$method, , $upstream, $evidence] = GrowthRoutes::PUBLIC_TABLE[(int) $request->route()->defaults['_growth_public_row']];

        $body = $method === 'GET' ? [] : $request->json()->all() + $request->request->all();
        unset($body['actor'], $body['ip'], $body['user_agent']);
        if ($evidence) {
            $body['ip'] = $request->ip();
            $body['user_agent'] = mb_substr((string) $request->userAgent(), 0, 500);
        }

        $path = $upstream;
        foreach (array_filter($request->route()->parameters(), 'is_string') as $name => $value) {
            $path = str_replace('{'.$name.'}', rawurlencode($value), $path);
        }
        $query = $request->query();
        unset($query[GrowthRoutes::AS_PRODUCT]);

        try {
            $result = $client->forward($method, $path, $query, $body);
        } catch (GrowthServiceUnavailable $e) {
            report($e);

            return new JsonResponse(['error' => 'growth_unreachable', 'message' => 'This page could not be reached just now. Please try again in a minute.'], 503);
        }

        if ($result['status'] >= 500) {
            report(new \RuntimeException("growth-service public proxy: {$method} {$path} answered {$result['status']}"));

            return new JsonResponse(['error' => 'growth_error', 'message' => 'Something went wrong on our side. It has been reported; please try again later.'], 502);
        }

        return new JsonResponse($result['body'], $result['status']);
    }
}
