<?php

namespace Shirahcan\GrowthClient\Testing;

use Shirahcan\GrowthClient\Facts\FactsUnavailable;
use Shirahcan\GrowthClient\Knowledge\KnowledgeSinkAdapter;

/**
 * A KnowledgeSinkAdapter for tests (S4a). Records every call in order and is idempotent the way a product must be:
 * an upsert of a post already held stores it once. Tell it to answer `held`, or to be not available, so a caller can
 * test both outcomes.
 */
class FakeKnowledgeSink implements KnowledgeSinkAdapter
{
    /** @var list<array{op: string, id: string, post?: array<string, mixed>, reason?: string}> */
    public static array $calls = [];

    /** @var array<string, array<string, mixed>> the rows the fake holds, by post id */
    public static array $rows = [];

    private static ?string $heldReason = null;

    private static ?string $unavailable = null;

    public static function reset(?string $heldReason = null, ?string $unavailable = null): void
    {
        self::$calls = [];
        self::$rows = [];
        self::$heldReason = $heldReason;
        self::$unavailable = $unavailable;
    }

    public function upsert(array $post): array
    {
        $this->guard();
        self::$calls[] = ['op' => 'upsert', 'id' => (string) $post['id'], 'post' => $post];
        self::$rows[(string) $post['id']] = $post;

        return self::$heldReason !== null
            ? ['state' => 'held', 'reason' => self::$heldReason]
            : ['state' => 'served', 'reason' => null];
    }

    public function remove(string $postId, string $reason): array
    {
        $this->guard();
        self::$calls[] = ['op' => 'remove', 'id' => $postId, 'reason' => $reason];
        $had = isset(self::$rows[$postId]);
        unset(self::$rows[$postId]);

        return ['state' => $had ? 'removed' : 'none'];
    }

    private function guard(): void
    {
        if (self::$unavailable !== null) {
            throw new FactsUnavailable(self::$unavailable);
        }
    }
}
