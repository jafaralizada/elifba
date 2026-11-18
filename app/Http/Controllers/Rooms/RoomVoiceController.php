<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\SetRoomVoice;
use App\Domain\Rooms\Data\RoomVoiceSwitchData;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The host's switch «Canlı səs» (docs/architecture.md §12 "Live voice",
 * ADR 031 decision 9): `PUT /rooms/{room}/voice` `{on}` (`RoomPolicy::voice`:
 * the host of an open room while the instance's voice is on). It answers
 * 204, or 422 `busy`; every device learns from the state.
 */
final class RoomVoiceController extends Controller
{
    public function __invoke(Request $request, Room $room, SetRoomVoice $setRoomVoice): Response
    {
        $setRoomVoice->handle($room, RoomVoiceSwitchData::validate($request->all()));

        return response()->noContent();
    }
}
