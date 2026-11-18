<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\SetRoomPlayerVoice;
use App\Domain\Rooms\Data\RoomPlayerVoiceData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The host's «Səsə burax», «Susdur», «Danışmağa icazə ver»
 * (docs/architecture.md §12 "Live voice", ADR 031 decision 9):
 * `PUT /rooms/{room}/members/{member}/voice` `{standing}` — `{member}` a
 * row of this room (`scopeBindings()`), never the host's own
 * (`RoomPolicy::voice`). It answers 204; every device learns from the
 * state.
 */
final class RoomPlayerVoiceController extends Controller
{
    public function __invoke(Request $request, Room $room, RoomPlayer $member, SetRoomPlayerVoice $setRoomPlayerVoice): Response
    {
        $setRoomPlayerVoice->handle($room, $member, RoomPlayerVoiceData::validate($request->all()));

        return response()->noContent();
    }
}
