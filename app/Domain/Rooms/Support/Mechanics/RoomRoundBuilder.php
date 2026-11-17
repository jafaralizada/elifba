<?php

namespace App\Domain\Rooms\Support\Mechanics;

use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use Random\Randomizer;

/**
 * The rules of one room mechanic on the server (docs/architecture.md §12
 * "Room games", ADR 023): how it builds a game's rounds from a level of
 * `content/rooms/levels.json`. One implementation per mechanic, given by
 * `RoomPlayable::builder()`.
 *
 * Every random choice — the content, the order, the item ids — comes from
 * `$random`, which `StartRoomGame` seeds with the game's `seed`, so the
 * same level, count and seed build the same rounds. Item ids are 8
 * characters from it and carry no position (`RoomItemIds`).
 */
interface RoomRoundBuilder
{
    /**
     * @param  int  $count  how many rounds, at least 1
     * @return list<BuiltRoomRoundData>
     */
    public function build(RoomLevelData $level, int $count, Randomizer $random): array;
}
