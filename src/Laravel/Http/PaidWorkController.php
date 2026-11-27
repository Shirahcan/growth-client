<?php

namespace Shirahcan\GrowthClient\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Shirahcan\GrowthClient\Conversions\PaidWorkAdapter;
use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Laravel\Http\Concerns\VerifiesServiceRead;

/**
 * Answers growth-service's signed "what did these addresses go on to buy?" (G6c2) from the product's PaidWorkAdapter:
 * 200 `{data: {people: {email: {first_case_at, cases, paid: {CUR: amount}}}}}`, 401, 422, 503 not_available, or 500.
 */
class PaidWorkController extends Controller
{
    use VerifiesServiceRead;

    public const MAX_EMAILS = 500;

    public function __invoke(Request $request): JsonResponse
    {
        if ($refused = $this->refuseUnsigned($request, 'paid-work')) {
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
            $adapter = $this->adapterFor(PaidWorkAdapter::class, 'growth-client.paid_work');
            if ($adapter === null) {
                return $this->notAvailable('This product does not say what its people went on to buy.');
            }
            $people = [];
            foreach ($adapter->paidWorkFor(array_values(array_unique($clean))) as $email => $work) {
                if (! is_string($email) || ! is_array($work) || ! is_string($work['first_case_at'] ?? null)) {
                    continue;
                }
                $paid = [];
                foreach ((array) ($work['paid'] ?? []) as $currency => $amount) {
                    if (is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency) && is_numeric($amount)) {
                        $paid[$currency] = round((float) $amount, 2);
                    }
                }
                $people[mb_strtolower($email)] = ['first_case_at' => $work['first_case_at'], 'cases' => max(0, (int) ($work['cases'] ?? 0)), 'paid' => (object) $paid];
            }
        } catch (FactsUnavailable $e) {
            return $this->notAvailable($e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['success' => false, 'error' => 'server_error', 'message' => 'The product could not read its paid work.'], 500);
        }

        return response()->json(['success' => true, 'data' => ['people' => (object) $people]]);
    }
}
