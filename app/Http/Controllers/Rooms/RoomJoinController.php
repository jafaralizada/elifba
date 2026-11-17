<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\JoinRoom;
use App\Domain\Rooms\Data\RoomCodeData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomCode;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The room's entrance, `/otaq` (docs/architecture.md §12 "Rooms and
 * joining", ADR 020 decisions 3–4): the code field, the join page and
 * «Qoşul». A code is its own code space: a pairing code means nothing
 * here. An unknown code, a closed room and an account the host removed get
 * the same 404; a locked or a full room is said on the join page and
 * nothing else about the room is. The join page shows the code and the
 * account's own name as the room will show it. 10 a minute per account
 * for all three routes together (`throttle:room-join`).
 */
final class RoomJoinController extends Controller
{
    public function entrance(Request $request, #[CurrentUser] User $account): Response|RedirectResponse
    {
        if ($request->query->has('code')) {
            return to_route('rooms.join', ['code' => RoomCodeData::validate($request->query())->code]);
        }

        return Inertia::render('rooms/join', ['code' => null, 'name' => $account->name]);
    }

    public function show(string $code, #[CurrentUser] User $account): Response|RedirectResponse
    {
        $room = $this->roomFor($code, $account);
        $row = $room->rowOf($account);

        if ($row !== null && $row->isPresent()) {
            return to_route('rooms.show', $room);
        }

        return Inertia::render('rooms/join', ['code' => $room->code, 'name' => $account->name]);
    }

    public function store(string $code, #[CurrentUser] User $account, JoinRoom $joinRoom): RedirectResponse
    {
        $room = $this->roomFor($code, $account);

        try {
            $joinRoom->handle($room, $account);
        } catch (ModelNotFoundException) {
            // Closed, or the account removed, a moment ago: the same 404 as any other.
            abort(404);
        }

        return to_route('rooms.show', $room);
    }

    /**
     * The open room with this code, unless the host removed the account
     * from it; a 404 otherwise, the same for every reason.
     */
    private function roomFor(string $code, User $account): Room
    {
        $room = RoomCode::resolve($code);

        abort_if($room === null || $room->players()->where('user_id', $account->id)->whereNotNull('removed_at')->exists(), 404);

        return $room;
    }
}
