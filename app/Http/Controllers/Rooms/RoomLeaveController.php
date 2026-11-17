<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\LeaveRoom;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * A present player leaves an open room (docs/architecture.md §12 "Rooms and
 * joining"); the code brings them back to the same row.
 */
final class RoomLeaveController extends Controller
{
    public function __invoke(Room $room, #[CurrentUser] User $account, LeaveRoom $leaveRoom): RedirectResponse
    {
        $row = $room->rowOf($account);

        if ($row !== null) {
            $leaveRoom->handle($room, $row);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('rooms.flash.left')]);

        return to_route('rooms.index');
    }
}
