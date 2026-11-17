<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\RemoveRoomPlayer;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * The host's «Çıxar» (docs/architecture.md §12 "Rooms and joining", ADR 020
 * decision 8): `{member}` is a row of this room (`scopeBindings()`), never
 * the host's own (`RoomPolicy::remove`).
 */
final class RoomMemberController extends Controller
{
    public function destroy(Room $room, RoomPlayer $member, RemoveRoomPlayer $removeRoomPlayer): RedirectResponse
    {
        $removeRoomPlayer->handle($room, $member);

        return back();
    }
}
