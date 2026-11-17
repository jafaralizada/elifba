<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Language\Support\DateLabel;
use App\Domain\Language\Support\Ordinal;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Config;

/**
 * An account's rooms on «Otaqlar» (docs/architecture.md §12 "Rooms and
 * joining", "Scoring and what is kept"): the rooms it hosts or has a row in
 * that was not removed — nobody else's, and none it took its name out of.
 * Open are those it hosts or is present in; the others (closed, or left)
 * are past, newest first, with the day in the interface language, the
 * games played in them (mechanic and difficulty, in order) and the
 * account's own row of the room's standing — its points and its place,
 * labelled here (`Ordinal::label()`), derived now by `RoomRanking` and
 * never stored; null for a host who did not play. No name is listed here:
 * a room has none, and its players are on its own page.
 *
 * @phpstan-type OpenRoomArray array{id: string, code: string|null, host: bool, players: int}
 * @phpstan-type PastRoomArray array{id: string, date: string, host: bool, players: int, games: list<array{mechanic: string, difficulty: int}>, mine: array{points: int, place: string|null}|null}
 */
final class RoomList
{
    /**
     * @return array{open: list<OpenRoomArray>, past: list<PastRoomArray>}
     */
    public static function of(User $account, string $locale): array
    {
        $rooms = Room::query()
            ->reachableBy($account)
            ->withCount(['players as present_count' => static fn (Builder $players) => $players
                ->whereNotNull('user_id')->whereNull('left_at')->whereNull('removed_at')])
            ->with([
                // The rows a ranking lists, as `RoomRanking::players()` reads them.
                'players' => static fn (HasMany $players) => $players
                    ->whereNull('removed_at')
                    ->with('user:id,name')
                    ->orderBy('joined_at')
                    ->orderBy('id'),
                'games.rounds:id,room_game_id,position,study_ms,answer_ms,closed_ms',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $open = [];
        $past = [];
        $timezone = Config::string('elifba.timezone');
        $nowMs = RoomClock::nowMs();

        foreach ($rooms as $room) {
            $host = $room->isHostedBy($account);
            /** @var RoomPlayer|null $row */
            $row = $room->players->firstWhere('user_id', $account->id);

            if ($room->isOpen() && ($host || ($row?->isPresent() ?? false))) {
                $open[] = ['id' => $room->id, 'code' => $room->code, 'host' => $host, 'players' => (int) $room->getAttribute('present_count')];

                continue;
            }

            $own = RoomResults::own($room, $row, $nowMs, $room->players);

            $past[] = [
                'id' => $room->id,
                'date' => DateLabel::day($room->created_at->setTimezone($timezone), $locale),
                'host' => $host,
                'players' => $room->players->count(),
                'games' => array_values($room->games->map(static fn (RoomGame $game): array => [
                    'mechanic' => $game->mechanic->value,
                    'difficulty' => $game->difficulty,
                ])->all()),
                'mine' => $own === null ? null : [
                    'points' => $own['points'],
                    'place' => $own['place'] === null ? null : Ordinal::label($own['place'], $locale),
                ],
            ];
        }

        return ['open' => $open, 'past' => $past];
    }
}
