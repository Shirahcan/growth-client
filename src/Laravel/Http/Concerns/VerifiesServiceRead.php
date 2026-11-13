<?php

namespace Shirahcan\GrowthClient\Laravel\Http\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Shirahcan\GrowthClient\WebhookSignature;

/**
 * The one check every signed READ from growth-service passes (facts, plan sources): the callback
 * secret, the calendar-service signature scheme, and a 5-minute window, because a read is answered at
 * once and never redelivered, so an old signed request is only a replay.
 *
 * ⚠ No secret configured = refuse everything: these routes are reachable from the internet.
 */
trait VerifiesServiceRead
{
    public const TOLERANCE_SECONDS = 300;

    /** Null when the call is signed and fresh; otherwise the 401 to return. */
    protected function refuseUnsigned(Request $request, string $what): ?JsonResponse
    {
        $secret = (string) config('growth-client.callback_secret');

        if ($secret === '' || ! WebhookSignature::verify($request->getContent(), $request->header('X-Growth-Signature'), $request->header('X-Growth-Timestamp'), $secret, null, self::TOLERANCE_SECONDS)) {
            report(new RuntimeException("growth-service {$what} call refused: ".($secret === '' ? 'GROWTH_SERVICE_CALLBACK_SECRET is not set' : 'signature mismatch or expired')));

            return response()->json(['success' => false, 'message' => 'Invalid signature'], 401);
        }

        return null;
    }

    /**
     * The product's adapter for a contract: a container binding wins, else the class named in config.
     * Resolved per call, so config set after boot is honoured. Null = the product has none.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T|null
     */
    protected function adapterFor(string $contract, string $configKey): ?object
    {
        if (app()->bound($contract)) {
            return app($contract);
        }

        $class = config($configKey);
        if (! is_string($class) || $class === '') {
            return null;
        }

        $adapter = app($class);
        if (! $adapter instanceof $contract) {
            throw new RuntimeException("{$configKey} [{$class}] does not implement ".class_basename($contract).'.');
        }

        return $adapter;
    }

    protected function notAvailable(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'error' => 'not_available', 'message' => $message], 503);
    }
}
