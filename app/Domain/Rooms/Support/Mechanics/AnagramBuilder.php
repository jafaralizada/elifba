<?php

namespace App\Domain\Rooms\Support\Mechanics;

use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Support\RoomWords;
use App\Domain\Language\Support\Az;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomAskData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Data\RoomSolutionData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Rounds of `anagram`, «Anaqram» (docs/architecture.md §12 "Room games",
 * ADR 023): the letters of a word of `content/rooms/words.json`, shuffled;
 * tap them into a word (`sequence`, `pick` = its letters). Nothing is
 * studied.
 *
 * - The word is drawn from the level's `word_length` among the words an
 *   anagram can be drawn from (`RoomWords::forAnagram()`), none twice in a
 *   game while the level's words last (`RoomWordDraw`).
 * - The tiles are its letters, kind `letter`, each cased by `Az::upper`
 *   (i → İ, ı → I), with ids of their own (`RoomItemIds`).
 * - The solution accepts every correct spelling of the list with those
 *   letters (`RoomWords::accepted()`: «alma» and «amal»), read in the
 *   tiles' capitals, so two tiles of one letter are interchangeable; its
 *   ids are the tiles in the drawn word's order, and its `word` the drawn
 *   word in capitals, which the reveal shows.
 * - The tiles are never in the order of an accepted spelling, not only of
 *   the drawn word: a shuffle that reads like one is turned by one place
 *   at a time, and if every turn reads like one, the orders of its letters
 *   are walked from the first until one reads like none
 *   (`RoomWords::forAnagram()` draws only words that have such an order).
 */
final readonly class AnagramBuilder implements RoomRoundBuilder
{
    public function __construct(
        private RoomWords $words,
    ) {}

    public function build(RoomLevelData $level, int $count, Randomizer $random): array
    {
        $length = $level->wordLength;

        if ($length === null || $level->studyMs !== 0) {
            throw new InvalidArgumentException('An anagram level needs a word length and nothing to study.');
        }

        $pool = $this->words->forAnagram($length);

        if ($pool === []) {
            throw new InvalidArgumentException("No word of {$length->min}–{$length->max} letters can be shown out of order.");
        }

        return array_map(
            fn (string $word): BuiltRoomRoundData => $this->round($level, $word, $random),
            RoomWordDraw::draw($pool, $count, $random),
        );
    }

    private function round(RoomLevelData $level, string $word, Randomizer $random): BuiltRoomRoundData
    {
        $ids = new RoomItemIds($random);
        $tiles = array_map(
            static fn (string $letter): RoomItemData => new RoomItemData($ids->next(), RoomItemKind::Letter, Az::upper($letter)),
            mb_str_split($word),
        );
        $accept = array_map(self::capitals(...), $this->words->accepted($word));

        // `$tiles` are still in the word's order: that order is the solution's.
        $solution = new RoomSolutionData(
            RoomAnswerShape::Sequence,
            array_map(static fn (RoomItemData $tile): string => $tile->id, $tiles),
            $accept,
            Az::upper($word),
        );

        /** @var list<RoomItemData> $shuffled */
        $shuffled = $random->shuffleArray($tiles);

        return new BuiltRoomRoundData(
            study: null,
            ask: new RoomAskData(RoomAnswerShape::Sequence, count($tiles), RoomLayout::Row, null, self::outOfOrder($shuffled, $accept)),
            solution: $solution,
            studyMs: 0,
            answerMs: $level->answerMs,
        );
    }

    /**
     * The tiles in an order no accepted spelling reads: the shuffle, or a
     * turn of it, or else the first order of the letters (by their bytes)
     * that reads like none.
     *
     * @param  list<RoomItemData>  $tiles
     * @param  list<list<string>>  $accept
     * @return list<RoomItemData>
     */
    private static function outOfOrder(array $tiles, array $accept): array
    {
        foreach (array_keys($tiles) as $turn) {
            $turned = [...array_slice($tiles, $turn), ...array_slice($tiles, 0, $turn)];

            if (! in_array(self::reading($turned), $accept, true)) {
                return $turned;
            }
        }

        $reading = self::reading($tiles);
        sort($reading, SORT_STRING);

        do {
            if (! in_array($reading, $accept, true)) {
                return self::laidOut($tiles, $reading);
            }
        } while (($reading = self::nextOrder($reading)) !== null);

        throw new InvalidArgumentException('These letters spell an accepted word in every order.');
    }

    /**
     * The tiles laid out to read `$reading`, each letter's tiles in their
     * order.
     *
     * @param  list<RoomItemData>  $tiles
     * @param  list<string>  $reading
     * @return list<RoomItemData>
     */
    private static function laidOut(array $tiles, array $reading): array
    {
        $left = $tiles;
        $laid = [];

        foreach ($reading as $letter) {
            foreach ($left as $index => $tile) {
                if ($tile->says() === $letter) {
                    $laid[] = $tile;
                    unset($left[$index]);

                    break;
                }
            }
        }

        return $laid;
    }

    /**
     * The next order of the letters in lexicographic order, or null after
     * the last: each different order once, however often a letter comes.
     *
     * @param  list<string>  $letters
     * @return list<string>|null
     */
    private static function nextOrder(array $letters): ?array
    {
        $pivot = count($letters) - 2;

        while ($pivot >= 0 && strcmp($letters[$pivot], $letters[$pivot + 1]) >= 0) {
            $pivot--;
        }

        if ($pivot < 0) {
            return null;
        }

        $swap = count($letters) - 1;

        while (strcmp($letters[$swap], $letters[$pivot]) <= 0) {
            $swap--;
        }

        [$letters[$pivot], $letters[$swap]] = [$letters[$swap], $letters[$pivot]];

        return [...array_slice($letters, 0, $pivot + 1), ...array_reverse(array_slice($letters, $pivot + 1))];
    }

    /**
     * A spelling as the tiles read it: its letters in capitals.
     *
     * @return list<string>
     */
    private static function capitals(string $spelling): array
    {
        return array_map(Az::upper(...), mb_str_split($spelling));
    }

    /**
     * @param  list<RoomItemData>  $tiles
     * @return list<string>
     */
    private static function reading(array $tiles): array
    {
        return array_map(static fn (RoomItemData $tile): string => $tile->says(), $tiles);
    }
}
