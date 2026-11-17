<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\LockRoomData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Support\Facades\DB;

/**
 * The host's «Kilidlə» (docs/architecture.md §12 "Rooms and joining",
 * ADR 020 decision 8): a locked room lets no new player in, and anyone who
 * left stays out until it is unlocked. A closed room — an idle one too,
 * before the scheduler writes it — keeps what it had. A change raises the
 * revision and signals the room.
 */
final readonly class LockRoom
{
    public function handle(Room $room, LockRoomData $data): void
    {
        DB::transaction(static function () use ($room, $data): void {
            $locked = RoomLock::room($room);

            if ($locked === null || ! $locked->isOpen() || ($locked->locked_at !== null) === $data->locked) {
                return;
            }

            $locked->locked_at = $data->locked ? now() : null;
            RoomSignal::raise($locked);
        });
    }
}
