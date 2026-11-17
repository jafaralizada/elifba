<?php

namespace App\Domain\Rooms\Support\Mechanics;

use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Support\RoomWords;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomAskData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Data\RoomSolutionData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Rounds of `spelling`, «Düzgün yazılış» (docs/architecture.md §12 "Room
 * games", ADR 023): one word of `content/rooms/words.json` written four
 * ways; choose the right one (`choice`). Nothing is studied.
 *
 * - The word is drawn from the level's `word_length` among the words that
 *   make three wrong spellings with its `swaps` (`RoomWords::forSpelling()`),
 *   none twice in a game while the level's words last (`RoomWordDraw`).
 * - The three wrong spellings are one of the word's ways to make them
 *   (`RoomWords::spellings()`), drawn from the seeded source: they swap
 *   letters for their confusables (`Alphabet::confusables()`) and none is
 *   a correct spelling — no word of the list, no also-correct spelling,
 *   no first name. At every letter where the four differ, two write it
 *   one way and two the other, so the right one is never the spelling the
 *   others agree with most.
 * - The four are items of kind `word`, written as the list writes them
 *   (lowercase), with ids of their own drawn before the order, and the
 *   right one's place among them comes from the seeded source: neither an
 *   id nor the order tells it. The solution is its id, and its `word` the
 *   right spelling, which the reveal shows.
 */
final readonly class SpellingBuilder implements RoomRoundBuilder
{
    public function __construct(
        private RoomWords $words,
    ) {}

    public function build(RoomLevelData $level, int $count, Randomizer $random): array
    {
        $length = $level->wordLength;
        $swaps = $level->swaps;

        if ($length === null || ! in_array($swaps, RoomWords::SWAPS, true) || $level->studyMs !== 0) {
            throw new InvalidArgumentException('A spelling level needs a word length, 1 or 2 swaps and nothing to study.');
        }

        $pool = $this->words->forSpelling($length, $swaps);

        if ($pool === []) {
            throw new InvalidArgumentException("No word of {$length->min}–{$length->max} letters makes three wrong spellings with {$swaps} swaps.");
        }

        return array_map(
            fn (string $word): BuiltRoomRoundData => $this->round($level, $word, $swaps, $random),
            RoomWordDraw::draw($pool, $count, $random),
        );
    }

    private function round(RoomLevelData $level, string $word, int $swaps, Randomizer $random): BuiltRoomRoundData
    {
        $ways = $this->words->spellings($word, $swaps);
        $wrong = $ways[$random->getInt(0, count($ways) - 1)];
        $ids = new RoomItemIds($random);
        $right = new RoomItemData($ids->next(), RoomItemKind::Word, $word);
        $others = array_map(static fn (string $spelling): RoomItemData => new RoomItemData($ids->next(), RoomItemKind::Word, $spelling), $wrong);

        /** @var list<RoomItemData> $options */
        $options = $random->shuffleArray([$right, ...$others]);

        return new BuiltRoomRoundData(
            study: null,
            ask: new RoomAskData(RoomAnswerShape::Choice, 1, RoomLayout::Row, null, $options),
            solution: new RoomSolutionData(RoomAnswerShape::Choice, [$right->id], word: $word),
            studyMs: 0,
            answerMs: $level->answerMs,
        );
    }
}
