<?php

namespace App\Domain\Rooms\Exceptions;

use App\Domain\Rooms\Models\Room;
use RuntimeException;

/**
 * A host chose a game on «Oyunlar» while a game is running in their open
 * room (docs/architecture.md §12 "Games first", ADR 026 decision 7): nothing
 * was written, and the caller sends the host to that room with
 * `rooms.errors.game_running`. It names the room by id only.
 */
final class RoomGameRunning extends RuntimeException
{
    public function __construct(public readonly Room $room)
    {
        parent::__construct("A game is running in room {$room->id}.");
    }
}
