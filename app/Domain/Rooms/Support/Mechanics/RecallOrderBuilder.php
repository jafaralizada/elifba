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
 * - The row's length is drawn from the level's `length` (3–12: two items
 *   could only be asked reversed, which would tell the answer), and no
 *   item comes twice in it (`RoomItemKind::distinct()` bounds the length):
 *   a repeated item would let the question alone rule orders out — {1, 1,
 *   2} with no item beside its equal can only be 1 2 1 — so the question
 *   says nothing of the order but that it is not the one shown.
 * - The question's items have ids of their own, never the row's, and are
 *   never in the order shown: when a shuffle reads like the row, it is
 *   turned by one place, which can never read like it, since its items
 *   are all different.
 * - The solution is the question's ids in the row's order, and the row's
 *   reading as the one accepted reading: two tiles that say the same are
 *   interchangeable.
 */
final readonly class RecallOrderBuilder implements RoomRoundBuilder
{
    public function build(RoomLevelData $level, int $count, Randomizer $random): array
    {
        $length = $level->length;

        if ($length === null || $length->min < 3 || $level->kinds === [] || $level->studyMs < 1) {
            throw new InvalidArgumentException('A recall_order level needs kinds, rows of three or more and time to study.');
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
     * A row of `$length` different items of the pool.
     *
     * @param  list<string>  $pool
     * @return list<string>
     */
    private static function row(array $pool, int $length, Randomizer $random): array
    {
        if ($length > count($pool)) {
            throw new InvalidArgumentException("A row of {$length} would repeat an item of {$pool[0]}…");
        }

        /** @var list<string> $shuffled */
        $shuffled = $random->shuffleArray($pool);

        return array_slice($shuffled, 0, $length);
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
