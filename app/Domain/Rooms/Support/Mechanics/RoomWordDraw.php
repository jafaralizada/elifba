<?php

namespace App\Domain\Rooms\Support\Mechanics;

use InvalidArgumentException;
use Random\Randomizer;

/**
 * The words of a word game's rounds (docs/architecture.md §12 "Room
 * games"): drawn from the seeded source without repeating one while the
 * level's words last — `ContentValidator` gives a level at least
 * `MIN_ROOM_WORDS`, more than a game's rounds — and only then from the
 * whole list again.
 */
final class RoomWordDraw
{
    /**
     * @param  list<string>  $pool
     * @return list<string> `$count` words
     */
    public static function draw(array $pool, int $count, Randomizer $random): array
    {
        if ($pool === []) {
            throw new InvalidArgumentException('There is no word to draw.');
        }

        $drawn = [];

        while (count($drawn) < $count) {
            /** @var list<string> $shuffled */
            $shuffled = $random->shuffleArray($pool);
            $drawn = [...$drawn, ...array_slice($shuffled, 0, $count - count($drawn))];
        }

        return $drawn;
    }
}
