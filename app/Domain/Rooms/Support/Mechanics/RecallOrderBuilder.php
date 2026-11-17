<?php

namespace App\Domain\Rooms\Support\Mechanics;

use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomAskData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Data\RoomSolutionData;
use App\Domain\Rooms\Data\RoomStudyData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Rounds of `recall_order`, «Ardıcıllığı yadda saxla» (docs/architecture.md
 * §12 "Room games", ADR 023): a row is shown for the level's `study_ms`,
 * then hidden; the question is the same items shuffled, to be tapped in
 * the order shown (`sequence`).
 *
 * - One kind per round, drawn from the level's `kinds`: digits 0–9, or
 *   letters of the alphabet (`Alphabet`) cased by `Az::upper`. Words (B6)
 *   and shapes (B4) are not built yet; the validator refuses a level that
 *   names them.
 * - The row's length is drawn from the level's `length` (2–12). An item
 *   may come back in a row, never right after itself, so a row always
 *   holds two different items.
 * - The question's items have ids of their own, never the row's, and are
 *   never in the order shown: when a shuffle reads like the row, it is
 *   turned by one place, which can never read like it, since no item
 *   stands beside its equal.
 * - The solution is the question's ids in the row's order, and the row's
 *   reading as the one accepted reading: two tiles that say the same are
 *   interchangeable.
 */
final readonly class RecallOrderBuilder implements RoomRoundBuilder
{
    public function build(RoomLevelData $level, int $count, Randomizer $random): array
    {
        $length = $level->length;

        if ($length === null || $length->min < 2 || $level->kinds === [] || $level->studyMs < 1) {
            throw new InvalidArgumentException('A recall_order level needs kinds, rows of two or more and time to study.');
        }

        $rounds = [];

        for ($index = 0; $index < $count; $index++) {
            $rounds[] = $this->round($level, $length, $random);
        }

        return $rounds;
    }

    private function round(RoomLevelData $level, RangeData $length, Randomizer $random): BuiltRoomRoundData
    {
        $kind = $level->kinds[$random->getInt(0, count($level->kinds) - 1)];
        $row = self::row(self::pool($kind), $random->getInt($length->min, $length->max), $random);
        $ids = new RoomItemIds($random);

        $study = array_map(static fn (string $text): RoomItemData => new RoomItemData($ids->next(), $kind, $text), $row);
        $asked = array_map(static fn (string $text): RoomItemData => new RoomItemData($ids->next(), $kind, $text), $row);

        // `$asked` is still in the row's order: that order is the solution.
        $solution = new RoomSolutionData(
            RoomAnswerShape::Sequence,
            array_map(static fn (RoomItemData $item): string => $item->id, $asked),
            [$row],
        );

        /** @var list<RoomItemData> $shuffled */
        $shuffled = $random->shuffleArray($asked);

        if (self::reading($shuffled) === $row) {
            $shuffled = [...array_slice($shuffled, 1), $shuffled[0]];
        }

        return new BuiltRoomRoundData(
            study: new RoomStudyData(RoomLayout::Row, null, $study),
            ask: new RoomAskData(RoomAnswerShape::Sequence, count($row), RoomLayout::Row, null, $shuffled),
            solution: $solution,
            studyMs: $level->studyMs,
            answerMs: $level->answerMs,
        );
    }

    /**
     * What a row of this kind is drawn from, as shown.
     *
     * @return list<string>
     */
    private static function pool(RoomItemKind $kind): array
    {
        return match ($kind) {
            RoomItemKind::Digit => array_map(strval(...), range(0, 9)),
            RoomItemKind::Letter => array_map(Az::upper(...), Alphabet::letters()),
            default => throw new InvalidArgumentException("recall_order does not show {$kind->value} yet."),
        };
    }

    /**
     * A row of `$length` items of the pool, none right after itself.
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    private static function row(array $pool, int $length, Randomizer $random): array
    {
        $row = [];
        $last = null;

        for ($index = 0; $index < $length; $index++) {
            // Draw from the pool without the last item: skip over its place.
            $pick = $random->getInt(0, count($pool) - ($last === null ? 1 : 2));

            if ($last !== null && $pick >= $last) {
                $pick++;
            }

            $row[] = $pool[$pick];
            $last = $pick;
        }

        return $row;
    }

    /**
     * @param  list<RoomItemData>  $items
     * @return list<string>
     */
    private static function reading(array $items): array
    {
        return array_map(static fn (RoomItemData $item): string => $item->says(), $items);
    }
}
