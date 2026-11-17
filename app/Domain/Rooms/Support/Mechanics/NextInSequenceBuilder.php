<?php

namespace App\Domain\Rooms\Support\Mechanics;

use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomSequenceRule;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomAskData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Data\RoomSolutionData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Rounds of `next_in_sequence`, «Sıranı davam etdir» (docs/architecture.md
 * §12 "Room games", ADR 023): a row of numbers follows one of the level's
 * rules (`SequenceRules`); choose what comes next among four (`choice`).
 * Nothing is studied: the row is shown with the question, in `ask.shown`,
 * and the four options are `ask.items`, all an answer may pick.
 *
 * - One rule per round, drawn from the level's `rules`; the row shows the
 *   level's `terms`, and every number of the round — the row and the four
 *   options — is inside its `range`.
 * - No row two rules continue differently: the row is checked against all
 *   six rules, not only the level's, and built again when any rule that
 *   fits it would continue it with another term. So every rule that fits
 *   a row continues it with the right option, and no near miss can be
 *   what such a rule continues with.
 * - The right term is never a term of the row, and neither is a near
 *   miss, so an option that repeats the row tells nothing. Three near
 *   misses, all different from the right term and from each other: what a rule that does not fit the row would
 *   make of its last terms (a misreading of it), one step too far, or a
 *   few off. Where the right term stands among the four, by size, is drawn
 *   first — so the right one is not the middle of the options more often
 *   than the edge — and their order on the page is drawn after.
 * - Ids come from the seeded source (`RoomItemIds`); the row's are its
 *   own, and the options get theirs in the order shown, which is drawn.
 */
final readonly class NextInSequenceBuilder implements RoomRoundBuilder
{
    /** How many options a round offers. */
    public const int OPTIONS = 4;

    /** How many rows a round may draw before the level is taken as one it cannot build. */
    public const int ATTEMPTS = 2_000;

    /** How far a near miss may be from the right term, besides a misreading. */
    private const array OFFSETS = [1, 2, 3, 4, 5, 10];

    public function build(RoomLevelData $level, int $count, Randomizer $random): array
    {
        $range = $level->range;
        $terms = $level->terms;

        if ($range === null || $terms === null || $level->rules === [] || $level->studyMs !== 0) {
            throw new InvalidArgumentException('A next_in_sequence level needs rules, terms and a range, and nothing to study.');
        }

        foreach ($level->rules as $rule) {
            if ($terms < $rule->minTerms()) {
                throw new InvalidArgumentException("A row of {$rule->value} shows at least {$rule->minTerms()} terms.");
            }
        }

        $rounds = [];

        for ($index = 0; $index < $count; $index++) {
            $rounds[] = $this->round($level, $terms, $range, $random);
        }

        return $rounds;
    }

    private function round(RoomLevelData $level, int $terms, RangeData $range, Randomizer $random): BuiltRoomRoundData
    {
        $rule = $level->rules[$random->getInt(0, count($level->rules) - 1)];
        // How many options are smaller than the right one, drawn before anything else.
        $below = $random->getInt(0, self::OPTIONS - 1);

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $row = SequenceRules::draw($rule, $terms + 1, $range, $random);

            if ($row === null) {
                continue;
            }

            $shown = array_slice($row, 0, $terms);
            $next = $row[$terms];

            // A next term that is also a term of the row would be the one option that is.
            if (in_array($next, $shown, true) || ! self::told($shown, $next)) {
                continue;
            }

            $misses = self::misses($shown, $next, $below, $range, $random);

            if ($misses === null) {
                continue;
            }

            return self::built($level, $shown, $next, $misses, $random);
        }

        throw new InvalidArgumentException("No row of {$rule->value} with {$terms} terms fits {$range->min}–{$range->max}.");
    }

    /**
     * Whether every rule of the six that fits the row continues it with
     * `$next`, so the row tells its next term.
     *
     * @param  list<int>  $shown
     */
    public static function told(array $shown, int $next): bool
    {
        foreach (RoomSequenceRule::cases() as $rule) {
            if (SequenceRules::fits($rule, $shown) && SequenceRules::continuation($rule, $shown) !== $next) {
                return false;
            }
        }

        return true;
    }

    /**
     * Three near misses, `$below` of them smaller than `$next`: misreadings
     * first, then numbers a few off; null when the range leaves too few on
     * a side.
     *
     * @param  list<int>  $shown
     * @return list<int>|null
     */
    private static function misses(array $shown, int $next, int $below, RangeData $range, Randomizer $random): ?array
    {
        $last = $shown[count($shown) - 1];
        $misread = [$next + ($next - $last)];

        foreach (RoomSequenceRule::cases() as $rule) {
            $continued = SequenceRules::continuation($rule, $shown);

            if ($continued !== null) {
                $misread[] = $continued;
            }
        }

        $near = [];

        foreach (self::OFFSETS as $offset) {
            $near[] = $next - $offset;
            $near[] = $next + $offset;
        }

        $fits = static fn (int $number): bool => $number >= max(1, $range->min) && $number <= $range->max && $number !== $next && ! in_array($number, $shown, true);
        $misread = array_values(array_unique(array_filter($misread, $fits)));
        $near = array_values(array_diff(array_unique(array_filter($near, $fits)), $misread));

        $side = static function (bool $smaller) use ($misread, $near, $next, $random): array {
            $on = static fn (int $number): bool => $smaller ? $number < $next : $number > $next;

            /** @var list<int> $first */
            $first = $random->shuffleArray(array_values(array_filter($misread, $on)));
            /** @var list<int> $then */
            $then = $random->shuffleArray(array_values(array_filter($near, $on)));

            return [...$first, ...$then];
        };

        $smaller = array_slice($side(true), 0, $below);
        $larger = array_slice($side(false), 0, self::OPTIONS - 1 - $below);

        if (count($smaller) + count($larger) !== self::OPTIONS - 1) {
            return null;
        }

        return [...$smaller, ...$larger];
    }

    /**
     * @param  list<int>  $shown
     * @param  list<int>  $misses
     */
    private static function built(RoomLevelData $level, array $shown, int $next, array $misses, Randomizer $random): BuiltRoomRoundData
    {
        $ids = new RoomItemIds($random);
        $number = static fn (int $value): RoomItemData => new RoomItemData($ids->next(), RoomItemKind::Number, (string) $value);

        $row = array_map($number, $shown);

        /** @var list<int> $options */
        $options = $random->shuffleArray([$next, ...$misses]);
        $items = array_map($number, $options);
        $right = array_values(array_filter($items, static fn (RoomItemData $item): bool => $item->text === (string) $next));

        return new BuiltRoomRoundData(
            study: null,
            ask: new RoomAskData(RoomAnswerShape::Choice, 1, RoomLayout::Row, null, $items, $row),
            solution: new RoomSolutionData(RoomAnswerShape::Choice, array_map(static fn (RoomItemData $item): string => $item->id, $right)),
            studyMs: 0,
            answerMs: $level->answerMs,
        );
    }
}
