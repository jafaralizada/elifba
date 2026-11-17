<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Language\Support\DateLabel;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

/**
 * An account's rooms on «Otaqlar» (docs/architecture.md §12 "Rooms and
 * joining", "Scoring and what is kept"): the rooms it hosts or has a row in
 * that was not removed — nobody else's, and none it took its name out of.
 * Open are those it hosts or is present in; the others (closed, or left)
 * are past, newest first, with the day in the interface language. No name
 * is listed here: a room has none, and its players are on its own page.
 */
final class RoomList
{
    /**
     * @return array{open: list<array{id: string, code: string|null, host: bool, players: int}>, past: list<array{id: string, date: string, host: bool, players: int}>}
     */
    public static function of(User $account, string $locale): array
    {
        $rooms = Room::query()
            ->reachableBy($account)
            ->withCount(['players as present_count' => static fn (Builder $players) => $players
                ->whereNotNull('user_id')->whereNull('left_at')->whereNull('removed_at')])
            ->withCount(['players as players_count' => static fn (Builder $players) => $players->whereNull('removed_at')])
            ->with(['players' => static fn ($players) => $players->where('user_id', $account->id)])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $open = [];
        $past = [];
        $timezone = Config::string('elifba.timezone');

        foreach ($rooms as $room) {
            $host = $room->isHostedBy($account);
            /** @var RoomPlayer|null $row */
            $row = $room->players->first();
            $present = (int) $room->getAttribute('present_count');

            if ($room->isOpen() && ($host || ($row?->isPresent() ?? false))) {
                $open[] = ['id' => $room->id, 'code' => $room->code, 'host' => $host, 'players' => $present];

                continue;
            }

            $past[] = [
                'id' => $room->id,
                'date' => DateLabel::day($room->created_at->setTimezone($timezone), $locale),
                'host' => $host,
                'players' => (int) $room->getAttribute('players_count'),
            ];
        }

        return ['open' => $open, 'past' => $past];
    }
}
