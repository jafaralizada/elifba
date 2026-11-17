<?php

use App\Domain\Rooms\Support\RoomPointsRule;

/*
 * The points of a room answer (docs/architecture.md §12 "Scoring and what
 * is kept", ADR 022 decision 1): 10 for a right answer plus 0–10 for speed,
 * ceil(10 × (answer_ms − elapsed_ms) ÷ answer_ms); 0 for a wrong one.
 */

it('gives 20 at once and 10 at the bell', function (int $elapsed, int $answer, int $points): void {
    expect(RoomPointsRule::points(true, $elapsed, $answer))->toBe($points);
})->with([
    'at once' => [0, 10_000, 20],
    'a millisecond in' => [1, 10_000, 20],
    'a tenth gone' => [1_000, 10_000, 19],
    'just past a tenth' => [1_001, 10_000, 19],
    'half way' => [5_000, 10_000, 15],
    'just past half way' => [5_001, 10_000, 15],
    'a step before the bell' => [9_000, 10_000, 11],
    'a millisecond before the bell' => [9_999, 10_000, 11],
    'at the bell' => [10_000, 10_000, 10],
    'a long round, a third gone' => [20_000, 60_000, 17],
    'the shortest round at once' => [0, 5_000, 20],
]);

it('gives nothing for a wrong answer', function (int $elapsed): void {
    expect(RoomPointsRule::points(false, $elapsed, 10_000))->toBe(0);
})->with([0, 2_500, 10_000]);

it('never goes below zero or above 20', function (): void {
    foreach ([5_000, 7_000, 10_000, 25_000, 60_000] as $answer) {
        foreach ([-5_000, -1, 0, 1, 777, $answer - 1, $answer, $answer + 1, $answer * 3] as $elapsed) {
            $right = RoomPointsRule::points(true, $elapsed, $answer);

            expect($right)->toBeGreaterThanOrEqual(10)->toBeLessThanOrEqual(20)
                ->and(RoomPointsRule::points(false, $elapsed, $answer))->toBe(0);
        }

        // Before the question counts as at once, after the bell as at the bell.
        expect(RoomPointsRule::points(true, -1, $answer))->toBe(20)
            ->and(RoomPointsRule::points(true, $answer * 3, $answer))->toBe(10);
    }

    expect(fn () => RoomPointsRule::points(true, 0, 0))->toThrow(InvalidArgumentException::class);
});
