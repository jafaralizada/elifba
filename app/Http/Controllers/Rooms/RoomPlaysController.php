<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\SetHostPlays;
use App\Domain\Rooms\Data\HostPlaysData;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The host's «Mən də oynayıram» (docs/architecture.md §12 "Games first",
 * ADR 026 decision 12): `PUT /rooms/{room}/plays` `{plays}`
 * (`RoomPolicy::start`: the host of an open room with no game running).
 * It answers 204 and nothing else: every device learns from the state
 * whether the host is among the players.
 */
final class RoomPlaysController extends Controller
{
    public function __invoke(Request $request, Room $room, SetHostPlays $setHostPlays): Response
    {
        $setHostPlays->handle($room, HostPlaysData::validate($request->all()));

        return response()->noContent();
    }
}
