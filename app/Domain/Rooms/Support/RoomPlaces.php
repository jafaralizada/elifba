<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Language\Support\Ordinal;
use App\Domain\Rooms\Models\Room;
use Illuminate\Support\Facades\Config;

/**
 * The labels of a room's places (docs/architecture.md §12 "Scoring and
 * what is kept"), from `Ordinal::label()` on the server, since a page never
 * suffixes a number itself: the label of place n at n − 1, as many as the
 * room has players not removed or may have (`elifba.rooms.max_players`).
 */
final class RoomPlaces
{
    /**
     * @return list<string>
     */
    public static function labels(Room $room, string $locale): array
    {
        $places = max(Config::integer('elifba.rooms.max_players'), $room->players()->whereNull('removed_at')->count());

        return array_map(static fn (int $place): string => Ordinal::label($place, $locale), range(1, $places));
    }
}
