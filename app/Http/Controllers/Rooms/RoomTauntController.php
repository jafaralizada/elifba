<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\SetRoomTaunts;
use App\Domain\Rooms\Actions\ThrowRoomTaunt;
use App\Domain\Rooms\Data\RoomTauntData;
use App\Domain\Rooms\Data\RoomTauntsSwitchData;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Taunts (docs/architecture.md §12 "Taunts (T2)", ADR 029 decisions 12–13):
 *
 * - `POST /rooms/{room}/taunts` `{event_id, target_id, taunt}` (zod
 *   `RoomTaunt` and `RoomTauntReceipt`): a present player's own taunt, the
 *   thrower being the session's account (`RoomPolicy::play`); the receipt
 *   is `{status, revision}`, never kept by a cache. 20 a minute per
 *   account (`throttle:room-taunts`).
 * - `PUT /rooms/{room}/taunts` `{on}`: the host's switch «Atmacalar»
 *   (`RoomPolicy::host`; `SetRoomTaunts` refuses a closed room). It
 *   answers 204: every device learns it from the state.
 */
final class RoomTauntController extends Controller
{
    public function store(Request $request, Room $room, #[CurrentUser] User $account, ThrowRoomTaunt $throwRoomTaunt): JsonResponse
    {
        $receipt = $throwRoomTaunt->handle($room, $account, RoomTauntData::validate($request->all()));

        return new JsonResponse($receipt->toArray(), 200, ['Cache-Control' => 'no-store, private']);
    }

    public function update(Request $request, Room $room, SetRoomTaunts $setRoomTaunts): Response
    {
        $setRoomTaunts->handle($room, RoomTauntsSwitchData::validate($request->all()));

        return response()->noContent();
    }
}
