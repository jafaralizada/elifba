<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use Illuminate\Support\Facades\DB;

/**
 * The host deletes a closed room (docs/architecture.md §12 "Scoring and
 * what is kept", ADR 022 decision 7): gone for everyone, with its players
 * and, from B3, its games, rounds and answers, by cascade. `RoomPolicy`
 * lets only the host delete, and only a closed room.
 */
final readonly class DeleteRoom
{
    public function handle(Room $room): void
    {
        DB::transaction(static function () use ($room): void {
            Room::query()->whereKey($room->id)->delete();
        });
    }
}
