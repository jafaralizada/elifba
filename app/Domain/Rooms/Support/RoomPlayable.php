<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Support\Mechanics\RecallCellsBuilder;
use App\Domain\Rooms\Support\Mechanics\RecallOrderBuilder;
use App\Domain\Rooms\Support\Mechanics\RoomRoundBuilder;

/**
 * The room mechanics a room can play (docs/architecture.md §12 "Room
 * games", ADR 023 decision 2): those whose view the room page draws. The
 * game's setup offers, and `RoomGameSetupData::rules()` accepts, only a
 * level of a mechanic listed here, so content may run ahead of the pages.
 * Every mechanic listed has its four levels (`RoomPlayableTest`).
 *
 * B3 lists `recall_order`, whose view B3 builds with the room page; B4
 * `recall_cells`, with its view.
 */
final class RoomPlayable
{
    /**
     * @return list<RoomMechanic>
     */
    public static function mechanics(): array
    {
        return [RoomMechanic::RecallOrder, RoomMechanic::RecallCells];
    }

    public static function plays(RoomMechanic $mechanic): bool
    {
        return in_array($mechanic, self::mechanics(), true);
    }

    /**
     * The round builder of a mechanic; every mechanic has one.
     */
    public static function builder(RoomMechanic $mechanic): RoomRoundBuilder
    {
        return match ($mechanic) {
            RoomMechanic::RecallOrder => new RecallOrderBuilder(app(ContentRepository::class)->roomShapes()),
            RoomMechanic::RecallCells => new RecallCellsBuilder,
        };
    }
}
