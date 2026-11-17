<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Support\Facades\DB;

/**
 * The host ends the running game (docs/architecture.md §12 "The race",
 * ADR 021 decision 10): `ended_ms` is now, and the timetable says it is
 * over from this moment. A round it cuts off never closes and gives
 * nothing (`RoomRanking`), and its solution is never served. A game
 * already over by its timetable is left as it is. A change raises the
 * revision and signals the room. `CloseRoom` ends a running game the same
 * way.
 */
final readonly class EndRoomGame
{
    public function handle(Room $room): void
    {
        DB::transaction(static function () use ($room): void {
            $locked = RoomLock::room($room);

            if ($locked !== null && self::end($locked, RoomClock::nowMs())) {
                RoomSignal::raise($locked);
            }
        });
    }

    /**
     * Ends the game running in a room whose row the caller holds locked.
     *
     * @return bool whether a game was running
     */
    public static function end(Room $locked, int $nowMs): bool
    {
        $game = $locked->runningGame($nowMs);

        if ($game === null) {
            return false;
        }

        $game->ended_ms = $nowMs;
        $game->save();

        return true;
    }
}
