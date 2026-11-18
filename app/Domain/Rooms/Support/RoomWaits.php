<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;

/**
 * Who a round waits for (docs/architecture.md §12 "The race", "Late, lost
 * and gone", ADR 021 decisions 7 and 10). A round of a room game waits for
 * a present player who has answered it, or whose own room page has read
 * the state since the round before it closed — for the first round, since
 * the game was started (`since()`; the read is `room_players.seen_ms`,
 * written by `RecordRoomRead`).
 *
 * A live page reads the state at every moment of the timetable, and at
 * the close of a round too (the signal, or its own timer at the bell); the
 * reveal gives it `reveal_ms` to do so before the next round opens. A
 * phone that is simply gone — a closed tab, a dead battery, a locked
 * screen — reads nothing more: the round it vanished in runs to its bell,
 * since it had read for that one, and no round after it waits for it. A
 * player who comes back, or joins late, is waited for from their page's
 * first read; a player who is thinking on a live phone, always.
 *
 * The early close (`RoomRoundClose`) and the count of a round's answers
 * (`RoomState`: `answered_count` of `present_count`) read the same rule,
 * so a full count never sits on an open round. A read only ever adds a
 * player to those a round waits for — `since()` is fixed once the round
 * opens, and `seen_ms` only grows — so a read never closes a round.
 */
final class RoomWaits
{
    /**
     * The moment from which a page's read makes round `$round` wait for its
     * player: when the game was started (its `created_at`, to the second)
     * for round 0, and the close of the round before for every other.
     */
    public static function since(RoomGame $game, int $round): int
    {
        return $round === 0
            ? (int) $game->created_at->getTimestampMs()
            : $game->timetable()->closes($round - 1);
    }

    /**
     * Whether the round that `$sinceMs` begins waits for `$row`: a present
     * player who has answered it, or whose page has read since then.
     */
    public static function waitsFor(RoomPlayer $row, int $sinceMs, bool $answered): bool
    {
        return $row->isPresent() && ($answered || ($row->seen_ms !== null && $row->seen_ms >= $sinceMs));
    }

    /**
     * The `since()` of the round a read at `$nowMs` can still count for —
     * the round in its countdown, study or answer, or the next one during
     * a reveal; null once nothing more is asked of anyone (the last reveal,
     * a game that is over), when a read changes nothing.
     */
    public static function readCountsFrom(RoomGame $game, int $nowMs): ?int
    {
        $timetable = $game->timetable();
        $moment = $timetable->moment($nowMs);

        if ($moment->round === null) {
            return null;
        }

        $round = $moment->phase === RoomPhase::Reveal ? $moment->round + 1 : $moment->round;

        return $round < $timetable->count() ? self::since($game, $round) : null;
    }
}
