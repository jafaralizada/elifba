<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Language\Support\AzCollator;
use App\Domain\Rankings\Support\DenseRank;
use App\Domain\Rooms\Data\RoomRankedPlayerData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use Illuminate\Database\Eloquent\Collection;

/**
 * A room's ranking (docs/architecture.md §12 "Scoring and what is kept",
 * ADR 022 decision 4), derived at each request and never stored: the
 * points of `room_answers` per player over **closed** rounds only — a round
 * is closed once its close (the early close or the bell) has come, by the
 * timetable at `$nowMs`, before the game was over: a round whose close is
 * the game's end was never revealed, and counts no more than one the end
 * cut off. The open round
 * never counts, so the list cannot tell who has just been right; a round
 * the host's end or the room's close cut off never counts at all.
 *
 * `ofGame()` ranks one game, `ofRoom()` every game of the room. The rows
 * are the room's players the host did not remove, nameless ones included
 * («Oyunçu», with their points). Places are dense (`DenseRank`), with no
 * tie-breaker; inside a place names stand in Azerbaijani order
 * (`AzCollator`), a nameless player last; a player with no points is
 * listed after the places, without one.
 */
final class RoomRanking
{
    /**
     * The rows a ranking lists: the room's players the host did not
     * remove, with their accounts' names, in the order they joined.
     *
     * @return Collection<int, RoomPlayer>
     */
    public static function players(Room $room): Collection
    {
        return $room->players()
            ->whereNull('removed_at')
            ->with('user:id,name')
            ->orderBy('joined_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  Collection<int, RoomPlayer>|null  $players  the room's rows not removed, in order; read when null
     * @return list<RoomRankedPlayerData>
     */
    public static function ofGame(RoomGame $game, int $nowMs, ?Collection $players = null): array
    {
        return self::rank($players ?? self::players($game->room), self::points(self::closedRounds($game, $nowMs)));
    }

    /**
     * @param  Collection<int, RoomPlayer>|null  $players  the room's rows not removed, in order; read when null
     * @return list<RoomRankedPlayerData>
     */
    public static function ofRoom(Room $room, int $nowMs, ?Collection $players = null): array
    {
        $rounds = [];

        foreach ($room->games()->with('rounds:id,room_game_id,position,study_ms,answer_ms,closed_ms')->get() as $game) {
            $rounds = [...$rounds, ...self::closedRounds($game, $nowMs)];
        }

        return self::rank($players ?? self::players($room), self::points($rounds));
    }

    /**
     * The ids of the game's rounds that closed by `$nowMs` and before the
     * game was over.
     *
     * @return list<string>
     */
    public static function closedRounds(RoomGame $game, int $nowMs): array
    {
        $timetable = $game->timetable();

        return array_values($game->rounds
            ->filter(static fn (RoomRound $round): bool => $timetable->closes($round->position) <= $nowMs && $timetable->closes($round->position) < $timetable->overMs())
            ->map(static fn (RoomRound $round): string => $round->id)
            ->all());
    }

    /**
     * Points per player row over these rounds.
     *
     * @param  list<string>  $rounds
     * @return array<string, int>
     */
    private static function points(array $rounds): array
    {
        $points = [];

        if ($rounds === []) {
            return $points;
        }

        foreach (RoomAnswer::query()->whereIn('room_round_id', $rounds)->get(['room_player_id', 'points']) as $answer) {
            $points[$answer->room_player_id] = ($points[$answer->room_player_id] ?? 0) + $answer->points;
        }

        return $points;
    }

    /**
     * @param  Collection<int, RoomPlayer>  $players
     * @param  array<string, int>  $points
     * @return list<RoomRankedPlayerData>
     */
    private static function rank(Collection $players, array $points): array
    {
        $scored = [];

        foreach ($players as $player) {
            if (($points[$player->id] ?? 0) > 0) {
                $scored[$player->id] = $points[$player->id];
            }
        }

        $places = DenseRank::places($scored);
        $rows = array_values($players->all());

        // Stable: rows that compare equal keep the order they joined in.
        usort($rows, static function (RoomPlayer $a, RoomPlayer $b) use ($places): int {
            $placeA = $places[$a->id] ?? PHP_INT_MAX;
            $placeB = $places[$b->id] ?? PHP_INT_MAX;
            $nameA = $a->user?->name;
            $nameB = $b->user?->name;

            return ($placeA <=> $placeB)
                ?: (($nameA === null) <=> ($nameB === null))
                ?: ($nameA !== null && $nameB !== null ? AzCollator::compare($nameA, $nameB) : 0);
        });

        return array_map(static fn (RoomPlayer $player): RoomRankedPlayerData => new RoomRankedPlayerData(
            player_id: $player->id,
            points: $points[$player->id] ?? 0,
            place: $places[$player->id] ?? null,
        ), $rows);
    }
}
