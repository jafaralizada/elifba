<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Content\Enums\RoomSpeaker;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomJoinLink;
use App\Domain\Rooms\Support\RoomPlaces;
use App\Domain\Rooms\Support\RoomState;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The big screen (docs/architecture.md §12 "The big screen", "Games first",
 * ADRs 021 and 026): `GET /rooms/{room}/screen`, page `rooms/screen`, for
 * the host of an open room in their own session on a laptop or a
 * television's browser. It gets what the room page gets to follow the
 * room — the state (`RoomState`, which it then reads from
 * `GET /rooms/{room}/state` as a host may, its `setup` included) and the
 * place labels — and `join`, the addresses of the QR and the code field
 * (`RoomJoinLink`), built on the server; `catalog`, the games the room's
 * choice may be, as the host's phone gets it (`RoomGameSetupData::
 * options()`); `sounds`, the URLs of the room's moments it plays as the
 * room's speaker (ADR 025 "Sound"), never a phone's own cues; and
 * `rivalry`, the URLs of the rivalry strip's three emoji (ADR 027).
 *
 * Opening it writes nothing — not even a read (`RecordRoomRead`): the
 * screen is not the host's phone, and a round never waits for the host
 * because the screen is on. Before a game and after one the page sends
 * the host's own requests, `rooms.setup.update` and `rooms.games.store`,
 * which `RoomPolicy::start` lets through for the host and nothing about
 * the page decides (ADR 026 decision 12); it holds no credential, route or
 * policy of its own, and it is not the guest board: `/lovhe`, the `board`
 * guard and its bearer never show a room. The page is docs/frontend.md
 * rule 15's.
 */
final class RoomScreenController extends Controller
{
    public function __invoke(Room $room, #[CurrentUser] User $account, ContentRepository $content): Response
    {
        return Inertia::render('rooms/screen', [
            'room' => ['id' => $room->id, 'status' => $room->status()->value],
            'state' => RoomState::for($room, $account)->toArray(),
            'place_labels' => RoomPlaces::labels($room, app()->getLocale()),
            'join' => RoomJoinLink::of($room),
            'catalog' => RoomGameSetupData::options(),
            'sounds' => $content->roomSounds()->urls(RoomSpeaker::Screen),
            'rivalry' => $content->roomRivalry()->urls(),
        ]);
    }
}
