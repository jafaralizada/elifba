<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Content\Enums\RoomSpeaker;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Actions\DeleteRoom;
use App\Domain\Rooms\Actions\LockRoom;
use App\Domain\Rooms\Actions\OpenRoom;
use App\Domain\Rooms\Actions\RecordRoomRead;
use App\Domain\Rooms\Data\LockRoomData;
use App\Domain\Rooms\Data\OpenRoomData;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomJoinLink;
use App\Domain\Rooms\Support\RoomList;
use App\Domain\Rooms\Support\RoomPlaces;
use App\Domain\Rooms\Support\RoomResults;
use App\Domain\Rooms\Support\RoomState;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Otaqlar» and a room's page, in the player area (docs/architecture.md §12
 * "Rooms and joining", ADR 020); the pages are docs/frontend.md rule 15's.
 *
 * A room's page gets the state (`RoomState`, names read now) for the host
 * and a present player of an open room, and for everyone who was in a
 * closed one — its results; a player who left an open room sees the page
 * without it and comes back with the code. The state in the props is the
 * one `GET /rooms/{room}/state` answers, phase by phase, with nothing more
 * of a round; like that endpoint, the page records a player's read first
 * (`RecordRoomRead`), so a round waits for a player from the moment their
 * room page opens — the page a join leads to. Places are labelled here (`place_labels`: the label of place
 * n at n − 1, `Ordinal::label()`), as many as the room has players or may
 * have, since a page never suffixes a number itself (`RoomPlaces`). The
 * host of an open room also gets `setup`, what a new game may be
 * (`RoomGameSetupData::options()`: each game with its family and what
 * each level holds); whoever gets the state of an open room
 * gets `join`, the addresses its QR and its code field are at
 * (`RoomJoinLink`); and everyone who was in a closed room gets `games`,
 * each game with its ranking (`RoomResults`), named from the state's
 * players. Whoever follows an open room's state also gets `sounds`, the
 * URLs of a phone's own quiet cues (ADR 025 "Sound": its taps, its answer
 * sent and its verdict; never the room's moments, which are the
 * screen's). `rooms/index` lists the account's rooms (`RoomList`). No other
 * account's id or address reaches a room page — the shared `auth.user` is
 * the viewer's own — and no name goes into a URL or a flash.
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

    public function show(Room $room, #[CurrentUser] User $account, ContentRepository $content, RecordRoomRead $recordRead): Response
    {
        $gate = Gate::forUser($account);
        $open = $room->isOpen();
        $watches = $gate->allows('watch', $room);

        if ($watches) {
            $recordRead->handle($room, $account);
        }

        return Inertia::render('rooms/show', [
            'room' => ['id' => $room->id, 'status' => $room->status()->value],
            'state' => $watches || ! $open ? RoomState::for($room, $account)->toArray() : null,
            'place_labels' => RoomPlaces::labels($room, app()->getLocale()),
            'setup' => $open && $gate->allows('host', $room) ? RoomGameSetupData::options() : null,
            'join' => $open && $watches ? RoomJoinLink::of($room) : null,
            'games' => $open ? null : RoomResults::games($room, RoomClock::nowMs()),
            'sounds' => $open && $watches ? $content->roomSounds()->urls(RoomSpeaker::Phone) : null,
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
