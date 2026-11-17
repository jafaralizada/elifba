<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Support\Facades\DB;

/**
 * Closes a room (docs/architecture.md §12 "Rooms and joining", ADR 020
 * decision 7), by the host or by `CloseIdleRooms`: `closed_at`, and the
 * code cleared, so it opens nothing any more. Final: a closed room is its
 * results. A game still running in it ends now (`EndRoomGame::end()`), so
 * the round it cuts off gives nothing and is never revealed. Raises the
 * revision once and signals the room.
 */
final readonly class CloseRoom
{
    public function handle(Room $room): void
    {
        DB::transaction(static function () use ($room): void {
            $locked = RoomLock::room($room);

            if ($locked !== null) {
                self::close($locked);
            }
        });
    }

    /**
     * Closes a room whose row the caller holds locked — the host's close, or
     * `CloseIdleRooms`'; a closed one stays as it is.
     */
    public static function close(Room $locked): void
    {
        if ($locked->closed_at !== null) {
            return;
        }

        EndRoomGame::end($locked, RoomClock::nowMs());

        $locked->closed_at = now();
        $locked->code = null;
        RoomSignal::raise($locked);
    }
}
