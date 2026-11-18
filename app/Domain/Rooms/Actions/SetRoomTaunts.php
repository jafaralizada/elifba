<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomTauntsSwitchData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The host's switch «Atmacalar» (docs/architecture.md §12 "Taunts (T2)",
 * ADR 029 decision 12): `PUT /rooms/{room}/taunts` `{on}`, from «Otağın
 * idarəsi» or the start screen, at any time — mid-game too. Under the room
 * row's lock it refuses a closed room; a change marks the room active,
 * raises its revision and signals it, so every device's tray and cards
 * follow the state; asking for what already is changes nothing. Off, no
 * taunt is thrown or shown; the costs already paid stay.
 */
final readonly class SetRoomTaunts
{
    /**
     * @throws AuthorizationException when the room is closed
     */
    public function handle(Room $room, RoomTauntsSwitchData $data): void
    {
        DB::transaction(static function () use ($room, $data): void {
            $locked = RoomLock::room($room);

            if ($locked === null || ! $locked->isOpen()) {
                throw new AuthorizationException;
            }

            if ($locked->taunts === $data->on) {
                return;
            }

            $locked->taunts = $data->on;
            $locked->last_activity_at = now();
            RoomSignal::raise($locked);
        });
    }
}
