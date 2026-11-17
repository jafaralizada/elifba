<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Data\RoomSolutionData;
use App\Domain\Rooms\Enums\RoomAnswerShape;

/**
 * The judge of a room answer (docs/architecture.md §12 "Room games", ADR 023
 * decision 4), in PHP only — there is no TypeScript judge for rooms; the
 * cases are `tests/Fixtures/rooms/judge.json`. An answer is right or wrong
 * as a whole, and an id picked twice makes it wrong:
 *
 * - `choice`: the one picked id is the solution's;
 * - `set`: the picked ids are the solution's, in any order;
 * - `sequence`: what the picked items say, in order, equals one of the
 *   solution's accepted readings — so two tiles that say the same are
 *   interchangeable.
 *
 * `RecordRoomAnswer` refuses ids that are not the round's before it asks;
 * here an id that is none of `$items` is simply wrong.
 */
final class RoomJudge
{
    /**
     * @param  list<RoomItemData>  $items  the round's question (`ask.items`)
     * @param  list<string>  $picked  item ids, in the order tapped
     */
    public static function correct(RoomSolutionData $solution, array $items, array $picked): bool
    {
        if ($picked === [] || count(array_unique($picked)) !== count($picked)) {
            return false;
        }

        return match ($solution->shape) {
            RoomAnswerShape::Choice => $picked === $solution->ids,
            RoomAnswerShape::Set => count($picked) === count($solution->ids) && array_diff($picked, $solution->ids) === [],
            RoomAnswerShape::Sequence => self::reads($solution->accept, $items, $picked),
        };
    }

    /**
     * @param  list<list<string>>  $accept
     * @param  list<RoomItemData>  $items
     * @param  list<string>  $picked
     */
    private static function reads(array $accept, array $items, array $picked): bool
    {
        $says = [];

        foreach ($items as $item) {
            $says[$item->id] = $item->says();
        }

        $reading = [];

        foreach ($picked as $id) {
            if (! isset($says[$id])) {
                return false;
            }

            $reading[] = $says[$id];
        }

        return in_array($reading, $accept, true);
    }
}
