<?php

namespace App\Domain\Rooms\Enums;

/**
 * What `ThrowRoomTaunt` did with a taunt (docs/architecture.md §12
 * "Taunts (T2)", ADR 029 decision 13), the receipt's `status`:
 *
 * - `accepted`: stored now; it costs the thrower `RoomPointsRule::
 *   TAUNT_COST`, counted from the next close;
 * - `duplicate`: its `event_id` is already stored;
 * - `late`: no game is running, its last round has closed, the room is
 *   closed or the thrower is not present;
 * - `off`: the host has switched taunts off;
 * - `cooling`: the thrower's last taunt of the game is younger than
 *   `elifba.rooms.taunts.cooldown_ms`;
 * - `no_points`: the thrower's balance is below the cost.
 *
 * Every status but `accepted` wrote nothing.
 */
enum RoomTauntStatus: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Late = 'late';
    case Off = 'off';
    case Cooling = 'cooling';
    case NoPoints = 'no_points';
}
