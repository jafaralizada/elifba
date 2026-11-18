<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Data\RoomGameData;
use App\Domain\Rooms\Data\RoomMineData;
use App\Domain\Rooms\Data\RoomMomentData;
use App\Domain\Rooms\Data\RoomPlayerData;
use App\Domain\Rooms\Data\RoomResultData;
use App\Domain\Rooms\Data\RoomRevealData;
use App\Domain\Rooms\Data\RoomRoundData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Data\RoomSummaryData;
use App\Domain\Rooms\Data\RoomViewerData;
use App\Domain\Rooms\Enums\RoomGamePhase;
use App\Domain\Rooms\Enums\RoomPhase;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * A room's state as one account sees it (docs/architecture.md §12 "The
 * state", "Adults' privacy", ADR 021 decision 6): the room, the viewer,
 * the players by the names their accounts have now — read here, never
 * stored in a room table — the latest game, its current round and the
 * ranking. A removed player is not listed; a row without an account has no
 * name. Nothing in it is an account id or an address.
 *
 * Everything time decides comes from the timetable at `RoomClock::nowMs()`,
 * and nothing here writes: a request after the bell is answered from the
 * reveal without anyone closing the round. A round serves only what its
 * phase allows (`RoomRoundData`): its material while it is studied, its
 * question from the answer phase, and the solution, the results and its
 * points only in the reveal. A round's `answered_count` of `present_count`
 * counts the present players it waits for (`RoomWaits`), as the early
 * close does, so a full count is a closed round; a player whose phone is
 * gone is not in it after the round they vanished in. A player's page
 * records its read first (`RecordRoomRead`), so the reader counts in the
 * state they get; the big screen records none. The ranking of a live
 * game counts its closed rounds only; once a game is over it is the
 * room's standing; before the first game there is none. A closed room —
 * an idle one too, before the scheduler writes it — has no live game.
 */
final class RoomState
{
    public static function for(Room $room, User $viewer): RoomStateData
    {
        $nowMs = RoomClock::nowMs();
        $rows = RoomRanking::players($room);
        $mine = $rows->first(static fn (RoomPlayer $row): bool => $row->user_id === $viewer->id);
        $game = $room->latestGame();
        $moment = $game === null || ! $room->isOpen() ? RoomMomentData::over() : $game->timetable()->moment($nowMs);
        $live = $game !== null && ! $moment->isOver();

        return new RoomStateData(
            revision: $room->revision,
            server_ms: $nowMs,
            room: RoomSummaryData::of($room),
            me: new RoomViewerData($mine?->id, $room->isHostedBy($viewer)),
            players: array_values($rows->map(static fn (RoomPlayer $row): RoomPlayerData => new RoomPlayerData(
                id: $row->id,
                name: $row->user?->name,
                present: $row->isPresent(),
                host: $row->user_id !== null && $row->user_id === $room->user_id,
            ))->all()),
            game: $game === null ? null : RoomGameData::of($game, $live ? RoomGamePhase::Live : RoomGamePhase::Over),
            round: $live ? self::round($game, $moment, $rows, $mine) : null,
            ranking: match (true) {
                $game === null => [],
                $live => RoomRanking::ofGame($game, $nowMs, $rows),
                default => RoomRanking::ofRoom($room, $nowMs, $rows),
            },
        );
    }

    /**
     * The current round of a live game, as its phase allows.
     *
     * @param  Collection<int, RoomPlayer>  $rows  the room's players not removed, in order
     */
    private static function round(RoomGame $game, RoomMomentData $moment, Collection $rows, ?RoomPlayer $mine): RoomRoundData
    {
        $timetable = $game->timetable();
        $index = $moment->round ?? 0;
        /** @var RoomRound $round */
        $round = $game->rounds->firstWhere('position', $index);
        $phase = $moment->phase;
        $asked = $phase === RoomPhase::Answer || $phase === RoomPhase::Reveal;
        $revealed = $phase === RoomPhase::Reveal;

        /** @var Collection<string, RoomAnswer> $answers */
        $answers = $asked ? $round->answers()->get()->keyBy('room_player_id') : new Collection;
        // Counted as the early close counts (`RoomWaits`): a full count is a closed round.
        $since = RoomWaits::since($game, $index);
        $waited = $rows->filter(static fn (RoomPlayer $row): bool => RoomWaits::waitsFor($row, $since, $answers->has($row->id)));
        $own = $mine === null ? null : $answers->get($mine->id);

        return new RoomRoundData(
            id: $round->id,
            index: $index,
            total: $timetable->count(),
            phase: $phase,
            opens_ms: $timetable->opens($index),
            answer_from_ms: $timetable->answerFrom($index),
            bell_ms: $timetable->bell($index),
            closes_ms: $revealed ? $timetable->closes($index) : null,
            next_ms: $revealed ? $timetable->next($index) : null,
            study: $phase === RoomPhase::Study ? $round->studyData() : null,
            ask: $asked ? $round->askData() : null,
            answered: $own !== null,
            answered_count: $waited->filter(static fn (RoomPlayer $row): bool => $answers->has($row->id))->count(),
            present_count: $waited->count(),
            reveal: $revealed ? new RoomRevealData(
                solution: $round->solutionData()->ids,
                word: $round->solutionData()->word,
                mine: $own === null ? null : RoomMineData::of($own),
                results: array_values($rows
                    ->filter(static fn (RoomPlayer $row): bool => $answers->has($row->id))
                    ->map(static function (RoomPlayer $row) use ($answers): RoomResultData {
                        /** @var RoomAnswer $answer */
                        $answer = $answers->get($row->id);

                        return new RoomResultData($row->id, $answer->correct, $answer->points);
                    })
                    ->all()),
            ) : null,
        );
    }
}
