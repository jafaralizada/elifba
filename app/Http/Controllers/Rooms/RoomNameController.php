<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\ForgetRoomPlayer;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * «Adımı sil» (docs/architecture.md §12 "Scoring and what is kept", ADR 022
 * decision 7): a player takes their name out of a closed room, which then
 * leaves their list.
 */
final class RoomNameController extends Controller
{
    public function destroy(Room $room, #[CurrentUser] User $account, ForgetRoomPlayer $forgetRoomPlayer): RedirectResponse
    {
        $forgetRoomPlayer->handle($room, $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('rooms.flash.forgotten')]);

        return to_route('rooms.index');
    }
}
