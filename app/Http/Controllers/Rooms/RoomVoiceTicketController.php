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
 * A ticket to the live voice (docs/architecture.md §12 "Live voice", ADR 031
 * decisions 14, 19 and 40): `POST /rooms/{room}/voice/tickets` `{agree?: true}`
 * (`RoomPolicy::listen`, `throttle:room-voice`). It answers `{url, token}`
 * as JSON with `no-store` — never a redirect, a prop or a URL — 422 on
 * `agree` without the account's agreement, 503 `{status: "unavailable"}`
 * when the voice server does not answer (`RoomVoiceUnreachable`), or 503
 * `{status: "busy"}` when the instance has no room for another voice in
 * use (`RoomVoiceBusy`, ADR 031 decision 38 as amended 2026-11-19). The
 * page asks for it by itself as well as on a tap (decision 40).
 */
final class RoomVoiceTicketController extends Controller
{
    public function __invoke(Request $request, Room $room, #[CurrentUser] User $account, JoinRoomVoice $joinRoomVoice): JsonResponse
    {
        $ticket = $joinRoomVoice->handle($room, $account, JoinRoomVoiceData::validate($request->all(), $account));

        return new JsonResponse($ticket->toArray(), 200, ['Cache-Control' => 'no-store, private']);
    }
}
