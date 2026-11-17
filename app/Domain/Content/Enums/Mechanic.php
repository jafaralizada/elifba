<?php

namespace App\Domain\Content\Enums;

/**
 * The rules of a round (docs/architecture.md §2). The rules themselves are
 * code in Play (round builders, scenes); the enum lives in Content because
 * `content/levels.json` and `content/prompts.json` name mechanics and the
 * validator must check them, and Content may not use Play.
 */
enum Mechanic: string
{
    /** A prompt names a target; one hit on it solves the round. */
    case Find = 'find';

    /**
     * «Oxu və vur», for beginners: no target; the level's items float on the
     * board, every one is right, and the round is solved when all are hit.
     */
    case Pop = 'pop';

    /** Build the shown syllable from letters. */
    case BuildSyllable = 'build_syllable';

    /** Build a word from its syllables. */
    case BuildWord = 'build_word';

    /** Put a sentence's words in order. */
    case BuildSentence = 'build_sentence';

    /**
     * «Say» (R5, ADR 012): the prompt shows one picture of the pack N times;
     * the items are numbers, and the one that says N solves the round.
     */
    case Count = 'count';

    /**
     * «İlk hərfi tap!»: the prompt shows a word's picture and names the
     * word; the items are letters, and the word's first letter solves the
     * round.
     */
    case FirstLetter = 'first_letter';

    /**
     * «Oxu və şəkli tap!»: the prompt writes a word without reading it
     * aloud; the items are pictures, and the word's picture solves the
     * round.
     */
    case ReadWord = 'read_word';

    /**
     * The units a level may combine this mechanic with.
     *
     * @return list<Unit>
     */
    public function units(): array
    {
        return match ($this) {
            self::Find, self::Pop => [Unit::Letter, Unit::Syllable, Unit::Word, Unit::Picture, Unit::Number],
            self::FirstLetter => [Unit::Letter],
            self::BuildSyllable => [Unit::Syllable],
            self::ReadWord, self::BuildWord => [Unit::Word],
            self::BuildSentence => [Unit::Sentence],
            self::Count => [Unit::Number],
        };
    }

    public function supports(Unit $unit): bool
    {
        return in_array($unit, $this->units(), true);
    }

    /**
     * Whether a round is solved by putting tiles into slots in order (and so
     * adds the level's `decoys`), rather than by hitting the level's `items`
     * in any order (`find`, `pop`, `count`, `first_letter`, `read_word`),
     * which have no slots.
     */
    public function builds(): bool
    {
        return match ($this) {
            self::Find, self::Pop, self::Count, self::FirstLetter, self::ReadWord => false,
            self::BuildSyllable, self::BuildWord, self::BuildSentence => true,
        };
    }
}
