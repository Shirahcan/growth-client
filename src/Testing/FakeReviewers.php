<?php

namespace Shirahcan\GrowthClient\Testing;

use Shirahcan\GrowthClient\Reviewers\ReviewersAdapter;

/**
 * A ReviewersAdapter with a fixed list, for tests (B3). Set the list with `FakeReviewers::with([...])` before the
 * route is called; the default is nobody, which is what a product with no licensed, opted-in person answers.
 */
class FakeReviewers implements ReviewersAdapter
{
    /** @var list<array<string, mixed>> */
    private static array $reviewers = [];

    private static int $maxAgeDays = 7;

    /** @param  list<array<string, mixed>>  $reviewers */
    public static function with(array $reviewers, int $registerMaxAgeDays = 7): void
    {
        self::$reviewers = $reviewers;
        self::$maxAgeDays = $registerMaxAgeDays;
    }

    public function reviewers(): array
    {
        return ['reviewers' => self::$reviewers, 'register_max_age_days' => self::$maxAgeDays];
    }
}
