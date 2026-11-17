<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomRound;

/**
 * The early close (docs/architecture.md §12 "The race", ADR 021 decision 7,
 * step 5): the one change a fact makes to the timetable. When every present
 * player has answered the round that is open for answers, its `closed_ms`
 * is that moment, and the reveal is served from it. Checked after an
 * answer (`RecordRoomAnswer`), a leave (`LeaveRoom`), a removal
 * (`RemoveRoomPlayer`) and a return (`JoinRoom`: a player who had answered
 * comes back to a round nobody else is in), so a round never waits for
 * someone who is gone. An account deleted mid-round is not seen here
 * (nothing outside `Rooms` may call it): its round runs to the bell.
 *
 * Present is counted at the moment of the check: a host who does not play
 * has no row and is not counted; a late joiner is, from the moment of
 * joining. A round with no present player left does not close early — it
 * runs to its bell, as the rounds after it would: there is nobody to
 * reveal it to sooner, and nobody's answer to wait for.
 *
 * Each caller holds the room's row locked and locks the round's row after
 * it, always in that order (`RoomLock`).
 */
final class RoomRoundClose
{
    /**
     * After the present players changed: closes the round that is open for
     * answers in the room's game, if everyone present has answered it.
     *
     * @return bool whether a round closed
     */
    public static function whenPresenceChanges(Room $locked, int $nowMs): bool
    {
        if (! $locked->isOpen()) {
            return false;
        }

        $game = $locked->runningGame($nowMs);
        $moment = $game?->timetable()->moment($nowMs);

        if ($game === null || $moment?->phase !== RoomPhase::Answer || $moment->round === null) {
            return false;
        }

        $round = RoomLock::round($game->rounds->firstWhere('position', $moment->round));

        return $round !== null && self::ifEveryoneAnswered($locked, $round, $nowMs);
    }

    /**
     * Closes `$round` at `$nowMs` if every present player of the room has
     * answered it; the caller holds both rows locked and knows the round
     * is open for answers.
     *
     * @return bool whether it closed
     */
    public static function ifEveryoneAnswered(Room $locked, RoomRound $round, int $nowMs): bool
    {
        $present = $locked->players()->present()->pluck('id');

        if ($present->isEmpty() || $round->answers()->whereIn('room_player_id', $present)->count() < $present->count()) {
            return false;
        }

        $round->closed_ms = $nowMs;
        $round->save();

        return true;
    }
}
