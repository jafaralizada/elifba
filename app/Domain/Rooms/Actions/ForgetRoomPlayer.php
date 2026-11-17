<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomLock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A player takes their name out of a closed room (docs/architecture.md §12
 * "Scoring and what is kept", ADR 022 decision 7): the row loses its
 * account (`user_id` null) and stays as «Oyunçu» with its points, so the
 * others' places do not move; the room leaves that account's list. The
 * host keeps the room they opened. `RoomPolicy` lets it happen only to a
 * closed room and only to one's own row.
 */
final readonly class ForgetRoomPlayer
{
    public function handle(Room $room, User $account): void
    {
        DB::transaction(static function () use ($room, $account): void {
            $locked = RoomLock::room($room);

            $locked?->players()
                ->where('user_id', $account->id)
                ->whereNull('removed_at')
                ->update(['user_id' => null, 'updated_at' => now()]);
        });
    }
}
