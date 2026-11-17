<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Data\RoomPlayerData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Data\RoomSummaryData;
use App\Domain\Rooms\Data\RoomViewerData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * A room's state as one account sees it (docs/architecture.md §12 "The
 * state", "Adults' privacy"): the room, the viewer, the players by the
 * names their accounts have now — read here, never stored in a room table
 * — and, from B3, the game, the round and the ranking. A removed player is
 * not listed; a row without an account has no name. Nothing in it is an
 * account id or an address.
 */
final class RoomState
{
    public static function for(Room $room, User $viewer): RoomStateData
    {
        $rows = $room->players()
            ->whereNull('removed_at')
            ->with('user:id,name')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();

        $mine = $rows->first(static fn (RoomPlayer $row): bool => $row->user_id === $viewer->id);

        return new RoomStateData(
            revision: $room->revision,
            server_ms: (int) CarbonImmutable::now()->getTimestampMs(),
            room: RoomSummaryData::of($room),
            me: new RoomViewerData($mine?->id, $room->isHostedBy($viewer)),
            players: array_values($rows->map(static fn (RoomPlayer $row): RoomPlayerData => new RoomPlayerData(
                id: $row->id,
                name: $row->user?->name,
                present: $row->isPresent(),
                host: $row->user_id !== null && $row->user_id === $room->user_id,
            ))->all()),
        );
    }
}
