<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\CloseRoom;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * The host closes the room (docs/architecture.md §12 "Rooms and joining"):
 * final; the page becomes its results.
 */
final class RoomCloseController extends Controller
{
    public function __invoke(Room $room, CloseRoom $closeRoom): RedirectResponse
    {
        $closeRoom->handle($room);

        return to_route('rooms.show', $room);
    }
}
