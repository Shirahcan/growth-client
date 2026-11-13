<?php

namespace Shirahcan\GrowthClient;

/**
 * Verifies a callback from growth-service: headers `X-Growth-Signature` (base64) and
 * `X-Growth-Timestamp`, base64 HMAC-SHA256 over `"{timestamp}.{rawBody}"` with the base64-decoded
 * callback secret.
 *
 * source: calendar-client `WebhookSignature` (the scheme calendar-service and video-service use), so
 * one implementation of the scheme is read the same way across the estate.
 *
 * Handlers MUST also be idempotent on the body's `event_id`: a retried delivery is the same event.
 */
final class WebhookSignature
{
    /** A callback older than this is refused (replay). Matches the service's longest backoff. */
    public const TOLERANCE_SECONDS = 86400;

    public static function verify(string $rawBody, ?string $signature, ?string $timestamp, string $base64Secret, ?int $now = null): bool
    {
        if ($signature === null || $signature === '' || $timestamp === null || ! ctype_digit($timestamp) || $base64Secret === '') {
            return false;
        }

        if (abs(($now ?? time()) - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $secret = base64_decode($base64Secret, true);
        if ($secret === false || $secret === '') {
            return false;
        }

        return hash_equals(self::sign($rawBody, $timestamp, $secret), $signature);
    }

    /** The signature for a body (used by the service, and by tests). `$secret` is the raw (decoded) secret. */
    public static function sign(string $rawBody, string $timestamp, string $secret): string
    {
        return base64_encode(hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret, true));
    }
}
