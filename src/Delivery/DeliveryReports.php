<?php

namespace Shirahcan\GrowthClient\Delivery;

use Shirahcan\GrowthClient\Exceptions\GrowthServiceException;
use Shirahcan\GrowthClient\GrowthServiceClient;

/**
 * G5h: one way for every product to tell growth-service what happened to an email, whoever sent it. A hard bounce or
 * a spam complaint suppresses the address for the whole estate at once; a soft bounce (a full inbox) is counted only;
 * CLEARED lifts the estate bounce block after an admin confirmed the address works (never a complaint).
 *
 * The product keeps reading its own bounce mailbox (its return path is the same whoever transmits) and calls this.
 * {@see complaintsIn()} reads a mailbox provider's feedback-loop report (ARF, RFC 5965) so complaints arrive too.
 */
final class DeliveryReports
{
    public const PATH = 'api/v1/delivery-reports';

    public const HARD_BOUNCE = 'hard_bounce';

    public const SOFT_BOUNCE = 'soft_bounce';

    public const COMPLAINT = 'complaint';

    public const CLEARED = 'cleared';

    /** ARF Feedback-Types that mean "this person does not want this mail" (abuse, fraud, a virus report). */
    private const COMPLAINT_TYPES = ['abuse', 'fraud', 'virus'];

    public function __construct(private readonly GrowthServiceClient $client) {}

    /** @return array<string, string|null> the body the service takes */
    public static function body(string $email, string $verdict, ?string $detail = null, ?string $ref = null, ?string $source = null): array
    {
        if (! in_array($verdict, [self::HARD_BOUNCE, self::SOFT_BOUNCE, self::COMPLAINT, self::CLEARED], true)) {
            throw new \InvalidArgumentException("Unknown delivery verdict [{$verdict}].");
        }

        return [
            'email' => mb_strtolower(trim($email)),
            'verdict' => $verdict,
            'detail' => $detail !== null ? mb_substr($detail, 0, 255) : null,
            'ref' => $ref !== null ? mb_substr($ref, 0, 191) : null,
            'source' => $source !== null ? mb_substr($source, 0, 32) : null,
        ];
    }

    /**
     * Forward one verdict. Throws when the service refuses or cannot be reached, so the caller can queue a retry
     * (a retry with the same ref is harmless).
     *
     * @return array<string, mixed>
     */
    public function report(string $email, string $verdict, ?string $detail = null, ?string $ref = null, ?string $source = null): array
    {
        $answer = $this->client->forward('POST', self::PATH, [], self::body($email, $verdict, $detail, $ref, $source));
        if ($answer['status'] !== 200) {
            throw new GrowthServiceException((string) ($answer['body']['message'] ?? 'The delivery report was not recorded.'), 'http_'.$answer['status'], $answer['status']);
        }

        return (array) ($answer['body']['data'] ?? []);
    }

    /**
     * The complaints in a raw feedback-loop email (ARF, RFC 5965): the complained-about recipient, the original
     * message's id (to make a retry harmless) and the report type. Empty for any other email.
     *
     * The recipient is `Original-Rcpt-To` when the provider sends it, else the `To` of the attached original. Many
     * providers redact the recipient; such a report names nobody and is skipped (it cannot be acted on).
     *
     * @return list<array{email: string, ref: ?string, type: string}>
     */
    public static function complaintsIn(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        if (! preg_match('/content-type:\s*multipart\/report[^\n]*(?:\n[ \t][^\n]*)*report-type\s*=\s*"?feedback-report/i', $raw)) {
            return [];
        }
        if (! preg_match('/content-type:\s*message\/feedback-report[^\n]*\n(?:[^\n]+\n)*?\n(.*?)(?:\n--|\z)/is', $raw, $part)) {
            return [];
        }
        $fields = self::fields($part[1]);
        $type = mb_strtolower(trim((string) ($fields['feedback-type'] ?? '')));
        if (! in_array($type, self::COMPLAINT_TYPES, true)) {
            return [];
        }

        $recipients = array_filter(array_map(fn ($v) => self::address($v), (array) ($fields['original-rcpt-to'] ?? [])));
        $original = '';
        if (preg_match('/content-type:\s*(?:message\/rfc822|text\/rfc822-headers)[^\n]*\n(?:[^\n]+\n)*?\n(.*)/is', $raw, $m)) {
            $original = $m[1];
        }
        $headers = self::fields(explode("\n\n", $original, 2)[0]);
        if ($recipients === [] && isset($headers['to'])) {
            $recipients = array_filter([self::address((string) (is_array($headers['to']) ? $headers['to'][0] : $headers['to']))]);
        }
        $id = $headers['message-id'] ?? null;
        $ref = $id === null ? null : trim((string) (is_array($id) ? $id[0] : $id));

        return array_values(array_map(fn (string $e) => ['email' => $e, 'ref' => $ref, 'type' => $type], array_unique($recipients)));
    }

    /** @return array<string, string|list<string>> header-style fields, unfolded, lower-cased names; repeats become lists */
    private static function fields(string $block): array
    {
        $out = [];
        $block = preg_replace("/\n[ \t]+/", ' ', $block) ?? $block;
        foreach (explode("\n", $block) as $line) {
            if (! preg_match('/^([A-Za-z0-9-]+):\s*(.*)$/', $line, $m)) {
                continue;
            }
            $name = mb_strtolower($m[1]);
            if (isset($out[$name])) {
                $out[$name] = array_merge((array) $out[$name], [$m[2]]);
            } else {
                $out[$name] = $m[2];
            }
        }

        return $out;
    }

    private static function address(string $value): ?string
    {
        if (preg_match('/<([^>]+)>/', $value, $m)) {
            $value = $m[1];
        }
        $value = mb_strtolower(trim(preg_replace('/^rfc822;\s*/i', '', trim($value)) ?? ''));

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null;
    }
}
