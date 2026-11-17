<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Support\Facades\DB;

/**
 * The host's «Çıxar» (docs/architecture.md §12 "Rooms and joining", ADR 020
 * decision 8): `removed_at` on a player's row, for good — that account no
 * longer finds the room, cannot come back with the code, and its answers
 * leave the ranking. Never the host's own row (`RoomPolicy::remove`), and
 * nothing in a closed room, an idle one included. Raises the revision and
 * signals the room. B3 adds the early close of a round nobody else is
 * waiting for.
 */
final readonly class RemoveRoomPlayer
{
    public function handle(Room $room, RoomPlayer $member): void
    {
        DB::transaction(static function () use ($room, $member): void {
            $locked = RoomLock::room($room);
            $row = $locked?->players()->whereKey($member->id)->first();

            if ($locked === null || ! $locked->isOpen() || $row === null || $row->removed_at !== null || $row->user_id === $locked->user_id) {
                return;
            }

            $row->removed_at = now();
            $row->save();

            RoomSignal::raise($locked);
        });
    }
}
