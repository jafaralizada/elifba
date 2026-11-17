<?php

namespace App\Domain\Rooms\Enums;

/**
 * Where a room's latest game stands in the state (docs/architecture.md §12
 * "The state", `game.phase`): `live` from its start until its timetable
 * says it is over — the countdown, the rounds and their reveals — and
 * `over` after that, when the room is a lobby again. Derived from the
 * timetable at each request, never stored.
 */
enum RoomGamePhase: string
{
    case Live = 'live';
    case Over = 'over';
}
