<?php

namespace App\Domain\Rooms\Exceptions;

use RuntimeException;

/**
 * A call an action owed the voice server — to make, close or mute in a
 * voice room, or to take a player out of it — did not get through
 * (docs/architecture.md §12 "Live voice", ADR 031 decision 22). Reported,
 * never thrown at the request: the write is already committed, and
 * `SweepRoomVoices` finishes the job. It names the room by id and the
 * cause by class only, never a player, as `RoomChangeNotBroadcast` does.
 */
final class RoomVoiceNotCut extends RuntimeException
{
    public function __construct(
        public readonly string $roomId,
        public readonly string $call,
        string $cause,
    ) {
        parent::__construct("{$call} for room {$roomId} did not reach the voice server: {$cause}.");
    }
}
