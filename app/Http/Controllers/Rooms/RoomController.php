<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Language\Support\Ordinal;
use App\Domain\Rooms\Actions\DeleteRoom;
use App\Domain\Rooms\Actions\LockRoom;
use App\Domain\Rooms\Actions\OpenRoom;
use App\Domain\Rooms\Data\LockRoomData;
use App\Domain\Rooms\Data\OpenRoomData;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomList;
use App\Domain\Rooms\Support\RoomState;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Otaqlar» and a room's page, in the player area (docs/architecture.md §12
 * "Rooms and joining", ADR 020). Plain pages until B4 designs them.
 *
 * A room's page gets the state (`RoomState`, names read now) for the host
 * and a present player of an open room, and for everyone who was in a
 * closed one — its results; a player who left an open room sees the page
 * without it and comes back with the code. The state in the props is the
 * one `GET /rooms/{room}/state` answers, phase by phase, with nothing more
 * of a round. Places are labelled here (`place_labels`: the label of place
 * n at n − 1, `Ordinal::label()`), as many as the room has players or may
 * have, since a page never suffixes a number itself. The host of an open
 * room also gets `setup`, what a new game may be
 * (`RoomGameSetupData::options()`). No other account's id or address
 * reaches a room page — the shared `auth.user` is the viewer's own — and
 * no name goes into a URL or a flash.
 */
final class RoomController extends Controller
{
    public function index(#[CurrentUser] User $account): Response
    {
        return Inertia::render('rooms/index', RoomList::of($account, app()->getLocale()));
    }

    public function store(Request $request, #[CurrentUser] User $account, OpenRoom $openRoom): RedirectResponse
    {
        $room = $openRoom->handle($account, OpenRoomData::validate($request->all()));

        return to_route('rooms.show', $room);
    }

    public function show(Room $room, #[CurrentUser] User $account): Response
    {
        $gate = Gate::forUser($account);
        $open = $room->isOpen();
        $places = max(Config::integer('elifba.rooms.max_players'), $room->players()->whereNull('removed_at')->count());
        $locale = app()->getLocale();

        return Inertia::render('rooms/show', [
            'room' => ['id' => $room->id, 'status' => $room->status()->value],
            'state' => $gate->allows('watch', $room) || ! $open ? RoomState::for($room, $account)->toArray() : null,
            'place_labels' => array_map(static fn (int $place): string => Ordinal::label($place, $locale), range(1, $places)),
            'setup' => $open && $gate->allows('host', $room) ? RoomGameSetupData::options() : null,
            'can' => [
                'host' => $gate->allows('host', $room),
                'leave' => $gate->allows('play', $room),
                'delete' => $gate->allows('delete', $room),
                'forget' => $gate->allows('forget', $room),
            ],
        ]);
    }

    public function update(Request $request, Room $room, LockRoom $lockRoom): RedirectResponse
    {
        $lockRoom->handle($room, LockRoomData::validate($request->all()));

        return back();
    }

    public function destroy(Room $room, DeleteRoom $deleteRoom): RedirectResponse
    {
        $deleteRoom->handle($room);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('rooms.flash.deleted')]);

        return to_route('rooms.index');
    }
}
