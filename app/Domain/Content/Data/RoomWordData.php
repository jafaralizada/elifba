<?php

namespace App\Domain\Content\Data;

/**
 * The adults' word list, `content/rooms/words.json` (docs/architecture.md
 * §12 "Room content", ADR 023 decision 10): our own list under MIT,
 * written in this repository and started from the theme packs' words,
 * which nothing reads at run time. Both lists are lowercase and in
 * Azerbaijani order (`ContentValidator`).
 *
 * - `words` are what the word games draw: an anagram's word, a spelling's
 *   word, a row of short words to recall.
 * - `alsoCorrect` are spellings that are correct too but never drawn —
 *   forms of a word («babam», «durun») and words that are no noun or
 *   adjective («arada») — found among the wrong spellings the confusable
 *   letters make: a spelling round never offers one as wrong, and an
 *   anagram accepts one its letters spell.
 *
 * `Content\Support\RoomWords` is what the games and the validator make of
 * it.
 *
 * @phpstan-type RoomWordArray array{version: int, words: list<string>, also_correct: list<string>}
 */
final readonly class RoomWordData
{
    /**
     * @param  int  $version  bumped on every change, as every content file's
     * @param  list<string>  $words
     * @param  list<string>  $alsoCorrect
     */
    public function __construct(
        public int $version,
        public array $words,
        public array $alsoCorrect = [],
    ) {}

    /**
     * @param  RoomWordArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['version'], $data['words'], $data['also_correct']);
    }
}
