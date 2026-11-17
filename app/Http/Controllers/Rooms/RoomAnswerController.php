<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\RecordRoomAnswer;
use App\Domain\Rooms\Data\RoomAnswerData;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /rooms/{room}/answers` `{event_id, round_id, picked}` (zod
 * `RoomAnswer` and `RoomAnswerReceipt`, docs/architecture.md §12 "The
 * race", ADR 021): a present player's own answer, the player being the
 * session's account (`RoomPolicy::play`). The receipt is `{status,
 * revision}`, the same for a right and a wrong answer, never kept by a
 * cache. 30 a minute per account (`throttle:room-answers`).
 */
final class RoomAnswerController extends Controller
{
    public function __invoke(Request $request, Room $room, #[CurrentUser] User $account, RecordRoomAnswer $recordRoomAnswer): JsonResponse
    {
        $receipt = $recordRoomAnswer->handle($room, $account, RoomAnswerData::validate($request->all()));

        return new JsonResponse($receipt->toArray(), 200, ['Cache-Control' => 'no-store, private']);
    }
}
