<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\EndRoomGame;
use App\Domain\Rooms\Actions\StartRoomGame;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * The host starts and ends a room's game (docs/architecture.md §12 "The
 * race", "Games first", ADRs 021 and 026 decision 9): `POST
 * /rooms/{room}/games` with no body — it starts the room's choice, and a
 * body that still arrives is ignored; «Yenidən oyna» is the same request
 * (`RoomPolicy::start`: the host of an open room with no game running,
 * from the phone or the screen) — and `DELETE /rooms/{room}/game`
 * (`RoomPolicy::end`: while one is running). Both answer 204 and nothing
 * else: every device, the host's too, learns the game from the state,
 * after `room.changed`.
 */
final class RoomGameController extends Controller
{
    public function store(Room $room, StartRoomGame $startRoomGame): Response
    {
        $startRoomGame->handle($room);

        return response()->noContent();
    }

    public function destroy(Room $room, EndRoomGame $endRoomGame): Response
    {
        $endRoomGame->handle($room);

        return response()->noContent();
    }
}
