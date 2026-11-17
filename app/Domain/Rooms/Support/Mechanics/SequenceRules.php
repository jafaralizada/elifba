<?php

namespace App\Domain\Rooms\Support\Mechanics;

use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Enums\RoomSequenceRule;
use Random\Randomizer;

/**
 * The arithmetic of the six `next_in_sequence` rules (docs/architecture.md
 * §12 "Room games", ADR 023 decision 7): the rules are code, here; their
 * names are `Content\Enums\RoomSequenceRule`, which `levels.json` uses.
 *
 * A rule continues a row from its last terms (`continuation()`): the same
 * step, the same factor, the step two back, the next square, the sum of
 * the last two, the step of the other row. A row fits a rule
 * (`fits()`) when every term after the first few is what the rule makes
 * of the terms before it; a row too short for that fits it trivially, as a
 * player could argue it does. `draw()` makes a row of a rule inside a
 * range from the seeded source, or nothing when the draw does not fit.
 */
final class SequenceRules
{
    /**
     * How many terms a rule needs before it can continue a row.
     */
    public static function needs(RoomSequenceRule $rule): int
    {
        return match ($rule) {
            RoomSequenceRule::Squares => 1,
            RoomSequenceRule::Arithmetic, RoomSequenceRule::Geometric, RoomSequenceRule::Fibonacci => 2,
            RoomSequenceRule::Alternating => 3,
            RoomSequenceRule::Interleaved => 4,
        };
    }

    /**
     * What the rule makes the next term, from the row's last terms; null
     * when the row is too short for it, or the rule gives no whole number
     * (a factor that does not divide, a term that is no square).
     *
     * @param  list<int>  $terms
     */
    public static function continuation(RoomSequenceRule $rule, array $terms): ?int
    {
        $count = count($terms);

        if ($count < self::needs($rule)) {
            return null;
        }

        $last = $terms[$count - 1];

        return match ($rule) {
            RoomSequenceRule::Arithmetic => $last + ($last - $terms[$count - 2]),
            RoomSequenceRule::Geometric => self::times($last, $terms[$count - 2]),
            RoomSequenceRule::Alternating => $last + ($terms[$count - 2] - $terms[$count - 3]),
            RoomSequenceRule::Squares => self::nextSquare($last),
            RoomSequenceRule::Fibonacci => $last + $terms[$count - 2],
            RoomSequenceRule::Interleaved => $terms[$count - 2] + ($terms[$count - 2] - $terms[$count - 4]),
        };
    }

    /**
     * Whether the row follows the rule: each term from the rule's
     * `needs()` on is what the rule makes of the terms before it. A row
     * with no such term fits whatever it is, as long as the rule can
     * continue it.
     *
     * @param  list<int>  $terms
     */
    public static function fits(RoomSequenceRule $rule, array $terms): bool
    {
        if (count($terms) < self::needs($rule)) {
            return false;
        }

        for ($index = self::needs($rule); $index < count($terms); $index++) {
            if (self::continuation($rule, array_slice($terms, 0, $index)) !== $terms[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * A row of `$count` terms that follows the rule, each inside the range,
     * with its parameters drawn from `$random`; null when this draw left
     * the range (the caller draws again) or the range has no room for the
     * rule at all.
     *
     * - arithmetic: a step from 1, up or down;
     * - geometric: a whole factor from 2, up or down;
     * - alternating: two different steps by turns that do not cancel out;
     * - squares: consecutive squares from 1², up;
     * - fibonacci: two first terms, then each the sum of the two before;
     * - interleaved: two rows by turns, each with a step of its own.
     *
     * @return list<int>|null
     */
    public static function draw(RoomSequenceRule $rule, int $count, RangeData $range, Randomizer $random): ?array
    {
        $low = max(1, $range->min);
        $high = $range->max;

        if ($count < 2 || $high <= $low) {
            return null;
        }

        $row = match ($rule) {
            RoomSequenceRule::Arithmetic => self::arithmetic($count, $low, $high, $random),
            RoomSequenceRule::Geometric => self::geometric($count, $low, $high, $random),
            RoomSequenceRule::Alternating => self::alternating($count, $low, $high, $random),
            RoomSequenceRule::Squares => self::squares($count, $low, $high, $random),
            RoomSequenceRule::Fibonacci => self::fibonacci($count, $low, $high, $random),
            RoomSequenceRule::Interleaved => self::interleaved($count, $low, $high, $random),
        };

        if ($row === null) {
            return null;
        }

        foreach ($row as $term) {
            if ($term < $low || $term > $high) {
                return null;
            }
        }

        return $row;
    }

    /**
     * @return list<int>|null
     */
    private static function arithmetic(int $count, int $low, int $high, Randomizer $random): ?array
    {
        $most = intdiv($high - $low, $count - 1);

        if ($most < 1) {
            return null;
        }

        $step = $random->getInt(1, $most);
        $start = $random->getInt($low, $high - $step * ($count - 1));
        $row = array_map(static fn (int $index): int => $start + $step * $index, range(0, $count - 1));

        return self::eitherWay($row, $random);
    }

    /**
     * @return list<int>|null
     */
    private static function geometric(int $count, int $low, int $high, Randomizer $random): ?array
    {
        $factors = array_values(array_filter(range(2, 10), static fn (int $factor): bool => $low * $factor ** ($count - 1) <= $high));

        if ($factors === []) {
            return null;
        }

        $factor = $factors[$random->getInt(0, count($factors) - 1)];
        $start = $random->getInt($low, intdiv($high, $factor ** ($count - 1)));
        $row = array_map(static fn (int $index): int => $start * $factor ** $index, range(0, $count - 1));

        return self::eitherWay($row, $random);
    }

    /**
     * @return list<int>|null
     */
    private static function alternating(int $count, int $low, int $high, Randomizer $random): ?array
    {
        $most = max(2, intdiv($high - $low, $count));
        $first = self::step($most, $random);
        $second = self::step($most, $random);

        if ($first === $second || $first + $second === 0) {
            return null;
        }

        $offsets = [0];

        for ($index = 1; $index < $count; $index++) {
            $offsets[] = $offsets[$index - 1] + ($index % 2 === 1 ? $first : $second);
        }

        return self::placed($offsets, $low, $high, $random);
    }

    /**
     * @return list<int>|null
     */
    private static function squares(int $count, int $low, int $high, Randomizer $random): ?array
    {
        $least = max(1, (int) ceil(sqrt($low)));
        $most = (int) floor(sqrt($high)) - ($count - 1);

        if ($most < $least) {
            return null;
        }

        $root = $random->getInt($least, $most);

        return array_map(static fn (int $index): int => ($root + $index) ** 2, range(0, $count - 1));
    }

    /**
     * @return list<int>
     */
    private static function fibonacci(int $count, int $low, int $high, Randomizer $random): array
    {
        // Each of the first two at most what keeps the last term inside.
        $grows = [1, 1];

        while (count($grows) < $count + 1) {
            $grows[] = $grows[count($grows) - 1] + $grows[count($grows) - 2];
        }

        $most = max($low, intdiv($high, $grows[$count]));
        $row = [$random->getInt($low, $most), $random->getInt($low, $most)];

        while (count($row) < $count) {
            $row[] = $row[count($row) - 1] + $row[count($row) - 2];
        }

        return $row;
    }

    /**
     * @return list<int>|null
     */
    private static function interleaved(int $count, int $low, int $high, Randomizer $random): ?array
    {
        $evens = intdiv($count + 1, 2);
        $odds = intdiv($count, 2);
        $most = max(2, intdiv($high - $low, max(1, $evens)));
        $steps = [self::step($most, $random), self::step($most, $random)];

        if ($steps[0] === $steps[1]) {
            return null;
        }

        $one = self::placed(array_map(static fn (int $index): int => $steps[0] * $index, range(0, $evens - 1)), $low, $high, $random);
        $other = self::placed(array_map(static fn (int $index): int => $steps[1] * $index, range(0, max(0, $odds - 1))), $low, $high, $random);

        if ($one === null || $other === null || $one[0] === $other[0]) {
            return null;
        }

        $row = [];

        for ($index = 0; $index < $count; $index++) {
            $row[] = $index % 2 === 0 ? $one[intdiv($index, 2)] : $other[intdiv($index, 2)];
        }

        return $row;
    }

    /**
     * A step of 1 to `$most`, up or down.
     */
    private static function step(int $most, Randomizer $random): int
    {
        return $random->getInt(1, $most) * ($random->getInt(0, 1) === 0 ? 1 : -1);
    }

    /**
     * The offsets moved to a start drawn so that every term is inside the
     * range; null when they span more than the range.
     *
     * @param  non-empty-list<int>  $offsets
     * @return list<int>|null
     */
    private static function placed(array $offsets, int $low, int $high, Randomizer $random): ?array
    {
        $from = $low - min($offsets);
        $to = $high - max($offsets);

        if ($to < $from) {
            return null;
        }

        $start = $random->getInt($from, $to);

        return array_map(static fn (int $offset): int => $start + $offset, $offsets);
    }

    /**
     * The row as it is, or read backwards: down by the same step or factor.
     *
     * @param  list<int>  $row
     * @return list<int>
     */
    private static function eitherWay(array $row, Randomizer $random): array
    {
        return $random->getInt(0, 1) === 0 ? $row : array_reverse($row);
    }

    /**
     * The next term of a row with the factor `$last ÷ $before`: `$last²
     * ÷ $before`, when it is a whole number.
     */
    private static function times(int $last, int $before): ?int
    {
        if ($before === 0 || ($last * $last) % $before !== 0) {
            return null;
        }

        return intdiv($last * $last, $before);
    }

    /**
     * The square after `$term`, when `$term` is one.
     */
    private static function nextSquare(int $term): ?int
    {
        if ($term < 0) {
            return null;
        }

        $root = (int) round(sqrt($term));

        return $root * $root === $term ? ($root + 1) * ($root + 1) : null;
    }
}
