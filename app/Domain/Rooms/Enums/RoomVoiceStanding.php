<?php

namespace App\Domain\Rooms\Enums;

/**
 * A player's standing in a room's live voice (docs/architecture.md §12
 * "Live voice", ADR 031 decision 8), derived by `RoomVoice::standingOf()`
 * from what the host decided and never stored: `waiting` — not let in, no
 * ticket; `muted` — let in, listens; `open` — let in, listens and may open
 * the microphone. The host's own row is always `open`.
 */
enum RoomVoiceStanding: string
{
    case Open = 'open';
    case Muted = 'muted';
    case Waiting = 'waiting';

    /** Whether a ticket of this standing may publish the microphone. */
    public function maySpeak(): bool
    {
        return $this === self::Open;
    }
}
