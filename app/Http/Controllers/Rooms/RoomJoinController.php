<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\JoinRoom;
use App\Domain\Rooms\Data\RoomCodeData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomCode;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CodeEntrance;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The room's entrance, `/oyun` (docs/architecture.md §12 "Rooms and
 * joining", "Games first", ADR 020 decisions 3–4, ADR 026 decision 6;
 * `/otaq`, its address before, redirects to it): the code field, the join page and
 * «Qoşul». A code is its own code space: a pairing code means nothing
 * here. An unknown code, a closed room and an account the host removed get
 * the same refusal, one sentence on the code field (`rooms.errors.unknown`)
 * and never an error page: where the code was typed, and at `/oyun` for a
 * link or a QR that opens nothing. A locked or a full room is said on the
 * join page and nothing else about the room is. The join page shows the
 * code and the account's own name as the room will show it. 10 tries of a
 * code a minute per account, the three ways together (`throttle:room-join`);
 * over it the same field says so, and `/oyun` without a code always opens.
 * `/otaq` and `/otaq/{code}` answer with a permanent redirect to the same
 * place under `/oyun`, a query kept, so a link, a habit or a README of
 * before still arrives; they try no code themselves.
 */
final class RoomJoinController extends Controller
{
    public function entrance(Request $request, #[CurrentUser] User $account): Response|RedirectResponse
    {
        if ($request->query->has('code')) {
            $code = RoomCodeData::validate($request->query())->code;

            // Refused where it was typed, as a malformed code is.
            $this->roomFor($code, $account, typed: true);

            return to_route('rooms.join', ['code' => $code]);
        }

        return Inertia::render('rooms/join', ['code' => null, 'name' => $account->name]);
    }

    public function oldEntrance(Request $request): RedirectResponse
    {
        return redirect()->to(route('rooms.entrance', $request->query()), 301);
    }

    public function oldJoin(Request $request, string $code): RedirectResponse
    {
        return redirect()->to(route('rooms.join', [...$request->query(), 'code' => $code]), 301);
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
            // Closed, or the account removed, a moment ago: the same refusal as any other.
            throw self::unknown();
        }

        return to_route('rooms.show', $room);
    }

    /**
     * The open room with this code, unless the host removed the account
     * from it; the same refusal otherwise, whatever the reason.
     *
     * @throws ValidationException on `code`
     */
    private function roomFor(string $code, User $account, bool $typed = false): Room
    {
        $room = RoomCode::resolve($code);

        if ($room === null || $room->players()->where('user_id', $account->id)->whereNotNull('removed_at')->exists()) {
            throw self::unknown($typed);
        }

        return $room;
    }

    /**
     * The one refusal of a code that opens no room for the account: a
     * sentence on `code` that names no reason. A typed code goes back to
     * its field; a link or a QR has none, and goes to the entrance's.
     */
    private static function unknown(bool $typed = false): ValidationException
    {
        return CodeEntrance::refusal('rooms.errors.unknown', $typed ? null : route('rooms.entrance'));
    }
}
