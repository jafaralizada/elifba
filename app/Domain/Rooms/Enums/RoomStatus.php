<?php

namespace App\Domain\Rooms\Enums;

/**
 * Whether a room is open (docs/architecture.md §12 "The state"): derived
 * from `closed_at` and the idle time (`Room::isOpen()`), never stored.
 */
enum RoomStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
