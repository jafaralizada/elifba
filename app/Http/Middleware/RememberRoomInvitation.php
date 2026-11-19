<?php

namespace App\Http\Middleware;

use App\Support\RoomInvitation;
use App\Support\RoomsArea;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `rooms.invitation` (docs/architecture.md §12 "The invitation link (I2)",
 * ADR 033 decision 8), on `GET /oyun/{code}` and ordered before `auth`:
 * a link opened by hand is kept in the session — its code and its tag, for
 * `elifba.rooms.invitation_minutes` — for every requester, a visitor and an
 * account that is not ready to play yet alike, so that the end of the way
 * in (the door, a login, Google, the role, the mail's link) joins as the
 * tap would have. It reads no room and writes nothing else; a link with a
 * malformed tag is not kept, since no room has it.
 */
final class RememberRoomInvitation
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->route('code');
        $tag = RoomInvitation::tagOf($request);

        if (RoomsArea::enabled()
            && is_string($code)
            && ($tag === null || RoomInvitation::wellFormed($tag))
            && RoomInvitation::openedByHand($request)) {
            RoomInvitation::remember($request, $code, $tag);
        }

        return $next($request);
    }
}
