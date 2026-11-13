<?php

namespace Shirahcan\GrowthClient\Segments;

/**
 * The ONE rule for an address's hash, identical to growth-service's `Suppression::hash()`:
 * sha256 of the lower-cased, trimmed address. A product's adapter hashes with this, so a re-cased or
 * padded address can never miss its subscriber.
 */
final class EmailHash
{
    public static function of(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /** Mask for display: "a***@example.com". Never the whole local part. */
    public static function mask(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '' || ! str_contains($email, '@')) {
            return null;
        }
        [$local, $domain] = explode('@', $email, 2);

        return mb_substr($local, 0, 1).'***@'.$domain;
    }
}
