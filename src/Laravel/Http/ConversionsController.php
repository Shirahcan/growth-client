<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\GrowthClient\Conversions\ConversionsAdapter;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Laravel\Http\Concerns\VerifiesServiceRead;

/**
 * Answers growth-service's signed "when did these addresses last convert?" (G6a2) from the product's
 * ConversionsAdapter: 200 `{data: {latest: {email: iso}}}`, 401, 422, 503 not_available, or 500.
 */
class ConversionsController extends Controller
{
    use VerifiesServiceRead;

    public const MAX_EMAILS = 500;

    public function __invoke(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'conversions')) {
            return $refused;
        }
        $body = json_decode($request->getContent(), true);
        $emails = is_array($body) ? ($body['emails'] ?? null) : null;
        if (! is_array($emails) || ! array_is_list($emails) || count($emails) > self::MAX_EMAILS) {
            return response()->json(['success' => false, 'message' => 'emails must be a list of at most '.self::MAX_EMAILS.' addresses'], 422);
        }
        $clean = [];
        foreach ($emails as $email) {
            if (! is_string($email) || ! str_contains($email, '@') || mb_strlen($email) > 254) {
                return response()->json(['success' => false, 'message' => 'each entry is an email address'], 422);
            }
            $clean[] = mb_strtolower(trim($email));
        }

        try {
            $adapter = $this->adapterFor(ConversionsAdapter::class, 'growth-client.conversions');
            if ($adapter === null) {
                return $this->notAvailable('This product does not say when its people become customers.');
            }
            $latest = [];
            foreach ($adapter->latestConversions(array_values(array_unique($clean))) as $email => $at) {
                if (is_string($email) && is_string($at) && $at !== '') {
                    $latest[mb_strtolower($email)] = $at;
                }
            }
        } catch (FactsUnavailable $e) {
            return $this->notAvailable($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'server_error', 'message' => 'The product could not read its conversions.'], 500);
        }

        return response()->json(['success' => true, 'data' => ['latest' => (object) $latest]]);
    }
}
