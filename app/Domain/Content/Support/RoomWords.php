<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomWordData;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;

/**
 * What the adults' word games make of the word list (docs/architecture.md
 * §12 "Room games", "Room content", ADR 023): the one place of the rules
 * the builders in `Rooms` follow and `ContentValidator` counts by, since
 * the validator may not name `Rooms` and still keeps every level inside
 * what its builder can build.
 *
 * - **Anagram.** A word's letters accept every correct spelling they
 *   spell (`accepted()`: the list's words and its `also_correct`, compared
 *   by `Az::lower`), and only a word whose letters can be laid out in an
 *   order none of those reads is drawn (`forAnagram()`).
 * - **Spelling.** A wrong spelling swaps letters of the word for their
 *   confusables (`Alphabet::confusables()`), and is never a correct
 *   spelling (`isCorrect()`: a word of the list, an `also_correct`, a
 *   first name of the name list) nor starts with «ğ», which no Azerbaijani
 *   word does. A round's four spellings are the ways of writing some
 *   letters of the word (`spellings()`):
 *   - `swaps` 1: two letters, each written two ways — the four ways of
 *     writing them, so two spellings differ in one letter or in both;
 *   - `swaps` 2: three letters, each written two ways, and every wrong
 *     spelling changes two of them, so any two of the four differ in two.
 *   Either way, at each letter where they differ two spellings write it
 *   one way and two the other: the right one is never the spelling the
 *   others agree with most, and only knowing the word tells it.
 * - **A row of words** (`recall_order`) shows words of at most
 *   `ROW_LETTERS` letters (`forRow()`).
 *
 * Words are compared in lowercase; the builders case what a round shows.
 */
final class RoomWords
{
    /**
     * A word of the list has 3–12 letters.
     */
    public const int MIN_LETTERS = 3;

    public const int MAX_LETTERS = 12;

    /**
     * The longest word a `recall_order` row shows: five fit a 375px phone's
     * row of tiles when its view gives a word a tile of its own.
     */
    public const int ROW_LETTERS = 5;

    /**
     * What a spelling level's `swaps` may be.
     */
    public const array SWAPS = [1, 2];

    /**
     * The wrong spellings of a round, beside the right one.
     */
    public const int WRONG_SPELLINGS = 3;

    /**
     * No Azerbaijani word starts with it, so no wrong spelling does: it
     * would be wrong for no reason the word gives.
     */
    private const string NEVER_FIRST = 'ğ';

    /**
     * @var list<string>
     */
    private readonly array $words;

    /**
     * Every correct spelling, lowercase.
     *
     * @var array<string, true>
     */
    private readonly array $correct;

    /**
     * The words and the also-correct spellings by their letters (sorted),
     * in list order.
     *
     * @var array<string, list<string>>
     */
    private readonly array $byLetters;

    /**
     * `spellings()` by `{swaps}:{word}`.
     *
     * @var array<string, list<list<string>>>
     */
    private array $spellings = [];

    /**
     * @param  list<string>  $words  what the games draw, lowercase
     * @param  list<string>  $alsoCorrect  spellings that are correct too, never drawn
     * @param  list<string>  $names  first names, in any case: never a wrong spelling
     */
    public function __construct(array $words, array $alsoCorrect = [], array $names = [])
    {
        $correct = [];
        $byLetters = [];

        foreach ([...$words, ...$alsoCorrect] as $spelling) {
            $lower = Az::lower($spelling);
            $correct[$lower] = true;
            $byLetters[self::letterKey($lower)][] = $lower;
        }

        foreach ($names as $name) {
            $correct[Az::lower($name)] = true;
        }

        $this->words = array_map(Az::lower(...), $words);
        $this->correct = $correct;
        $this->byLetters = $byLetters;
    }

    /**
     * @param  list<string>  $names  first names of the name list
     */
    public static function of(RoomWordData $list, array $names = []): self
    {
        return new self($list->words, $list->alsoCorrect, $names);
    }

    /**
     * Every word the games may draw, in list order.
     *
     * @return list<string>
     */
    public function words(): array
    {
        return $this->words;
    }

    /**
     * How many letters a word has.
     */
    public static function letters(string $word): int
    {
        return mb_strlen($word);
    }

    /**
     * The words with as many letters as the range allows, in list order.
     *
     * @return list<string>
     */
    public function ofLength(RangeData $length): array
    {
        return array_values(array_filter($this->words, static fn (string $word): bool => $length->contains(self::letters($word))));
    }

    /**
     * Whether a spelling is correct: a word of the list, an also-correct
     * spelling or a first name, in any case.
     */
    public function isCorrect(string $spelling): bool
    {
        return isset($this->correct[Az::lower($spelling)]);
    }

    // ── anagram ─────────────────────────────────────────────────────────

    /**
     * What an anagram of the word accepts: the word, then every other
     * correct spelling of the list with the same letters («alma», «amal»),
     * lowercase.
     *
     * @return list<string>
     */
    public function accepted(string $word): array
    {
        $lower = Az::lower($word);

        return array_values(array_unique([$lower, ...$this->byLetters[self::letterKey($lower)] ?? []]));
    }

    /**
     * The words of the range an anagram can be drawn from: those whose
     * letters can be laid out in an order no accepted spelling reads.
     *
     * @return list<string>
     */
    public function forAnagram(RangeData $length): array
    {
        return array_values(array_filter($this->ofLength($length), fn (string $word): bool => self::arrangements($word) > count($this->accepted($word))));
    }

    /**
     * In how many different orders the word's letters can be laid out:
     * n! over the factorial of each letter's count (12 letters at most).
     */
    public static function arrangements(string $word): int
    {
        $letters = mb_str_split(Az::lower($word));
        $orders = self::factorial(count($letters));

        foreach (array_count_values($letters) as $times) {
            $orders = intdiv($orders, self::factorial($times));
        }

        return $orders;
    }

    // ── spelling ────────────────────────────────────────────────────────

    /**
     * Every way to make a round's three wrong spellings of the word with
     * `$swaps` (see the class), in a fixed order — by the letters' places
     * in the word, then their confusables in `Alphabet` order — so a seeded
     * source picks the same one again. Each holds three different
     * spellings, none of them correct and none starting with «ğ»; empty
     * when the word makes none.
     *
     * @return list<list<string>>
     */
    public function spellings(string $word, int $swaps): array
    {
        $lower = Az::lower($word);

        return $this->spellings["{$swaps}:{$lower}"] ??= $this->makeSpellings($lower, $swaps, all: true);
    }

    /**
     * The words of the range a spelling round can be drawn from with
     * `$swaps`: those that make three wrong spellings.
     *
     * @return list<string>
     */
    public function forSpelling(RangeData $length, int $swaps): array
    {
        return array_values(array_filter(
            $this->ofLength($length),
            // One way is enough to know: the rest are made for a drawn word only.
            fn (string $word): bool => isset($this->spellings["{$swaps}:{$word}"]) ? $this->spellings["{$swaps}:{$word}"] !== [] : $this->makeSpellings($word, $swaps, all: false) !== [],
        ));
    }

    // ── recall_order ────────────────────────────────────────────────────

    /**
     * The words a `recall_order` row may show: 3 to `ROW_LETTERS` letters.
     *
     * @return list<string>
     */
    public function forRow(): array
    {
        return $this->ofLength(new RangeData(self::MIN_LETTERS, self::ROW_LETTERS));
    }

    /**
     * @param  bool  $all  every way, or only the first
     * @return list<list<string>>
     */
    private function makeSpellings(string $word, int $swaps, bool $all): array
    {
        if (! in_array($swaps, self::SWAPS, true)) {
            return [];
        }

        $letters = mb_str_split($word);
        /** @var array<int, list<string>> $choices the confusables of each letter that has some, by place */
        $choices = [];

        foreach ($letters as $place => $letter) {
            $confusables = Alphabet::isLetter($letter) ? Alphabet::confusables($letter) : [];

            if ($confusables !== []) {
                $choices[$place] = $confusables;
            }
        }

        $places = array_keys($choices);
        $spell = static function (array $swapped) use ($letters): string {
            $spelling = $letters;

            foreach ($swapped as $place => $letter) {
                $spelling[$place] = $letter;
            }

            return implode('', $spelling);
        };
        $made = [];

        foreach (self::combinations($places, $swaps === 1 ? 2 : 3) as $chosen) {
            foreach (self::product(array_map(static fn (int $place): array => $choices[$place], $chosen)) as $swapsTo) {
                $swap = array_combine($chosen, $swapsTo);

                $wrong = match ($swaps) {
                    // Two letters, x and y: x alone, y alone, both.
                    1 => [
                        $spell([$chosen[0] => $swap[$chosen[0]]]),
                        $spell([$chosen[1] => $swap[$chosen[1]]]),
                        $spell($swap),
                    ],
                    // Three letters: every two of them.
                    default => [
                        $spell([$chosen[0] => $swap[$chosen[0]], $chosen[1] => $swap[$chosen[1]]]),
                        $spell([$chosen[0] => $swap[$chosen[0]], $chosen[2] => $swap[$chosen[2]]]),
                        $spell([$chosen[1] => $swap[$chosen[1]], $chosen[2] => $swap[$chosen[2]]]),
                    ],
                };

                if (array_filter($wrong, fn (string $spelling): bool => $this->isCorrect($spelling) || str_starts_with($spelling, self::NEVER_FIRST)) === []) {
                    $made[] = $wrong;

                    if (! $all) {
                        return $made;
                    }
                }
            }
        }

        return $made;
    }

    /**
     * The `$size`-element subsets of `$items`, each in the items' order.
     *
     * @param  list<int>  $items
     * @return list<list<int>>
     */
    private static function combinations(array $items, int $size): array
    {
        if ($size === 0) {
            return [[]];
        }

        $combinations = [];

        foreach ($items as $index => $item) {
            foreach (self::combinations(array_slice($items, $index + 1), $size - 1) as $rest) {
                $combinations[] = [$item, ...$rest];
            }
        }

        return $combinations;
    }

    /**
     * Every way to pick one item of each list, in order.
     *
     * @param  list<list<string>>  $lists
     * @return list<list<string>>
     */
    private static function product(array $lists): array
    {
        $product = [[]];

        foreach ($lists as $list) {
            $next = [];

            foreach ($product as $picked) {
                foreach ($list as $item) {
                    $next[] = [...$picked, $item];
                }
            }

            $product = $next;
        }

        return $product;
    }

    private static function letterKey(string $lower): string
    {
        $letters = mb_str_split($lower);
        sort($letters, SORT_STRING);

        return implode('', $letters);
    }

    private static function factorial(int $n): int
    {
        return $n < 2 ? 1 : $n * self::factorial($n - 1);
    }
}
