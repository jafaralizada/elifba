<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomRoundClose;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Support\Facades\DB;

/**
 * A player leaves an open room (docs/architecture.md §12 "Rooms and
 * joining"): the row stays, with `left_at`, and the same account may come
 * back with the code to it. A round open for answers that every player
 * still present has answered closes now (`RoomRoundClose`): it never waits
 * for someone who is gone. Raises the revision and signals the room.
 */
final readonly class LeaveRoom
{
    public function handle(Room $room, RoomPlayer $player): void
    {
        DB::transaction(static function () use ($room, $player): void {
            $locked = RoomLock::room($room);
            $row = $locked?->players()->whereKey($player->id)->first();

            if ($locked === null || $row === null || ! $row->isPresent()) {
                return;
            }

            $row->left_at = now();
            $row->save();

            RoomRoundClose::whenPresenceChanges($locked, RoomClock::nowMs());
            RoomSignal::raise($locked);
        });
    }
}
