<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\JoinRoomVoice;
use App\Domain\Rooms\Data\JoinRoomVoiceData;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «Səsə qoşul» (docs/architecture.md §12 "Live voice", ADR 031 decisions
 * 14 and 19): `POST /rooms/{room}/voice/tickets` `{agree?: true}`
 * (`RoomPolicy::listen`, `throttle:room-voice`). It answers `{url, token}`
 * as JSON with `no-store` — never a redirect, a prop or a URL — 422 on
 * `agree` without the account's agreement, or 503 `{status: "unavailable"}`
 * when the voice server does not answer (`RoomVoiceUnreachable`).
 */
final class RoomVoiceTicketController extends Controller
{
    public function __invoke(Request $request, Room $room, #[CurrentUser] User $account, JoinRoomVoice $joinRoomVoice): JsonResponse
    {
        $ticket = $joinRoomVoice->handle($room, $account, JoinRoomVoiceData::validate($request->all(), $account));

        return new JsonResponse($ticket->toArray(), 200, ['Cache-Control' => 'no-store, private']);
    }
}
