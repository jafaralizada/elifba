<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * The host changes the room's choice of the next game (docs/architecture.md
 * §12 "Games first", ADR 026 decision 8): `PUT /rooms/{room}/setup` with the
 * whole choice, from the host's phone or the big screen alike. Under the
 * room row's lock it refuses a closed room and a room whose game is still
 * running — `RoomPolicy::start` refused them already, and a start at the
 * same moment meets here — then writes the three columns, marks the room
 * active, raises its revision and signals it: every device, the one that
 * sent it too, learns the choice from the state. The choice the room
 * already has changes nothing and signals nothing.
 */
final readonly class ChooseRoomGame
{
    /**
     * @throws AuthorizationException when the room is closed or a game is running in it
     */
    public function handle(Room $room, RoomGameSetupData $setup): void
    {
        DB::transaction(static function () use ($room, $setup): void {
            $locked = RoomLock::room($room);

            if ($locked === null || ! $locked->isOpen() || $locked->runningGame(RoomClock::nowMs()) !== null) {
                throw new AuthorizationException;
            }

            if ($locked->setup_mechanic === $setup->mechanic && $locked->setup_difficulty === $setup->difficulty && $locked->setup_rounds === $setup->rounds) {
                return;
            }

            $locked->setup_mechanic = $setup->mechanic;
            $locked->setup_difficulty = $setup->difficulty;
            $locked->setup_rounds = $setup->rounds;
            $locked->last_activity_at = now();
            RoomSignal::raise($locked);
        });
    }
}
