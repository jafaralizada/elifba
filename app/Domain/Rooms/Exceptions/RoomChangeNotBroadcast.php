<?php

namespace App\Domain\Rooms\Exceptions;

use RuntimeException;

/**
 * `room.changed` could not reach Reverb (down, unreachable, refused).
 * Reported, never thrown at the request: the change is already committed,
 * and a page without a socket polls the state (docs/architecture.md §12
 * "The race", ADR 021 decision 9). It names the room by id and the cause by
 * class only, never a player.
 */
final class RoomChangeNotBroadcast extends RuntimeException
{
    public function __construct(
        public readonly string $roomId,
        public readonly int $revision,
        string $cause,
    ) {
        parent::__construct("room.changed ({$revision}) for room {$roomId} was not broadcast: {$cause}.");
    }
}
