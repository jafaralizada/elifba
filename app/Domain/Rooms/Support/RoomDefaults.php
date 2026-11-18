<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Models\RoomGame;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * A new room's difficulty and rounds for the game chosen (docs/architecture.md
 * §12 "Games first", ADR 026 decision 10): those of the newest game in the
 * rooms this account hosted, when the chosen game has a level at that
 * difficulty and the rounds are inside `elifba.rooms.rounds`; otherwise
 * `elifba.rooms.defaults.difficulty` and `.rounds` — and, should the game
 * have no level at that difficulty, its lowest one.
 *
 * Derived at each opening from rows the account's own rooms keep for the
 * retention period, shown to that account alone, and gone with them:
 * nothing is stored on the account, and nothing is read about another
 * person (ADR 022 decision 8).
 */
final class RoomDefaults
{
    public static function for(User $host, RoomMechanic $mechanic): RoomGameSetupData
    {
        $newest = RoomGame::query()
            ->join('rooms', 'rooms.id', '=', 'room_games.room_id')
            ->where('rooms.user_id', $host->id)
            ->select('room_games.*')
            ->withCount('rounds')
            ->orderByDesc('rooms.created_at')
            ->orderByDesc('rooms.id')
            ->orderByDesc('room_games.position')
            ->first();

        if ($newest !== null) {
            $taken = new RoomGameSetupData($mechanic, $newest->difficulty, (int) $newest->getAttribute('rounds_count'));

            if ($taken->isPlayable()) {
                return $taken;
            }
        }

        $difficulty = Config::integer('elifba.rooms.defaults.difficulty');
        $difficulties = RoomGameSetupData::difficultiesOf($mechanic);

        return new RoomGameSetupData(
            $mechanic,
            in_array($difficulty, $difficulties, true) ? $difficulty : ($difficulties[0] ?? $difficulty),
            Config::integer('elifba.rooms.defaults.rounds'),
        );
    }
}
