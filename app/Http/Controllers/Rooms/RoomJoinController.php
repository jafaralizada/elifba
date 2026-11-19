<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\JoinRoom;
use App\Domain\Rooms\Data\RoomCodeData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomCode;
use App\Domain\Rooms\Support\RoomJoinLink;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CodeEntrance;
use App\Support\RoomInvitation;
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
 * before still arrives; they try no code themselves. `/oyun` also gets
 * `entrance`, its own address, whose origin the code field's camera
 * accepts (ADR 026 decision 15).
 *
 * From I2 (§12 "The invitation link (I2)", ADR 033 decisions 5–8) a link
 * carries the room's tag, and a link a person opened by hand
 * (`RoomInvitation::openedByHand`), or the end of a way in such an open
 * began (the session's invitation, used once), joins at once through
 * `JoinRoom`; the code field, the camera and every other request still get
 * the join page and «Qoşul».
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

        return Inertia::render('rooms/join', ['code' => null, 'name' => $account->name, 'entrance' => RoomJoinLink::entrance()]);
    }

    public function oldEntrance(Request $request): RedirectResponse
    {
        return redirect()->to(route('rooms.entrance', $request->query()), 301);
    }

    public function oldJoin(Request $request, string $code): RedirectResponse
    {
        return redirect()->to(route('rooms.join', [...$request->query(), 'code' => $code]), 301);
    }

    /**
     * The join page — or, for a link opened by hand or at the end of a way
     * in such an open began, the join itself (§12 "The invitation link
     * (I2)", ADR 033 decision 7). A `t` that is not the room's tag is the
     * one refusal; the host goes to the room as they are, a present player
     * too; a locked or a full room is said on the join page, as after «Qoşul».
     */
    public function show(Request $request, string $code, #[CurrentUser] User $account, JoinRoom $joinRoom): Response|RedirectResponse
    {
        $tag = RoomInvitation::tagOf($request);
        // Taken first, so that it is used once whatever the answer.
        $invited = RoomInvitation::pull($request, $code, $tag);
        $room = $this->roomFor($code, $account);

        if (! RoomJoinLink::tagMatches($room, $tag)) {
            throw self::unknown();
        }

        $row = $room->rowOf($account);

        // A link never changes whether the host plays: that is «Mən də oynayıram» alone.
        if ($room->isHostedBy($account) || ($row !== null && $row->isPresent())) {
            return to_route('rooms.show', $room);
        }

        if ($invited || RoomInvitation::openedByHand($request)) {
            try {
                $joinRoom->handle($room, $account);
            } catch (ModelNotFoundException) {
                throw self::unknown();
            } catch (ValidationException $refused) {
                // Locked or full: the join page with the reason, as after «Qoşul».
                return $this->joinPage($room, $account, $refused->errors());
            }

            return to_route('rooms.show', $room);
        }

        return $this->joinPage($room, $account);
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
     * Page `rooms/join` with the room's code and the account's own name, and
     * nothing else of the room; `$errors` puts a refusal on its `code`.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function joinPage(Room $room, User $account, array $errors = []): Response
    {
        $props = ['code' => $room->code, 'name' => $account->name, 'entrance' => null];

        if ($errors !== []) {
            $props['errors'] = array_map(static fn (array $messages): string => $messages[0] ?? '', $errors);
        }

        return Inertia::render('rooms/join', $props);
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
