<?php

namespace Tests\Support;

use App\Domain\Rooms\Actions\RecordRoomRead;
use App\Domain\Rooms\Models\Room;
use App\Models\User;

/**
 * The players' phones in a room, as a test makes them follow it
 * (docs/architecture.md §12 "The race", "Late, lost and gone"). A live room
 * page reads the state at every moment of the timetable, and a round waits
 * only for a present player who has answered it or whose page has read
 * the state since the round before it closed (`RoomWaits`). A test that
 * posts answers for several accounts first lets their phones read at the
 * moment it has travelled to — through `RecordRoomRead`, which the room
 * page and `GET /rooms/{room}/state` call — so the round waits for them;
 * an account it leaves out is a phone that is gone. A test of the rule
 * itself reads through the endpoint instead.
 */
final class RoomPhones
{
    /** Each account's room page reads the room's state now. */
    public static function read(Room $room, User ...$accounts): void
    {
        $fresh = Room::query()->findOrFail($room->id);

        foreach ($accounts as $account) {
            app(RecordRoomRead::class)->handle($fresh, $account);
        }
    }
}
