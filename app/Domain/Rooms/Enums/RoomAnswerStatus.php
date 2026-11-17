<?php

namespace App\Domain\Rooms\Enums;

/**
 * What `RecordRoomAnswer` did with an answer (docs/architecture.md §12 "The
 * race", ADR 021 decisions 3 and 7), the receipt's `status` — never whether
 * it was right:
 *
 * - `accepted`: judged, timed and stored now;
 * - `duplicate`: its `event_id` is already stored;
 * - `answered`: this player already answered the round;
 * - `late`: the round is not open for answers by the server's clock (before
 *   the question, after the bell or the early close, a round of another
 *   game, a game that is over, a closed room, a player who is not in the
 *   room any more); nothing was written.
 */
enum RoomAnswerStatus: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Answered = 'answered';
    case Late = 'late';
}
