<?php

namespace App\Domain\Rooms\Support\Mechanics;

use App\Domain\Content\Data\RoomCategoryData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomSource;
use App\Domain\Language\Support\Az;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomAskData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Data\RoomSolutionData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use Closure;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Rounds of `odd_one_out`, «Artıq olanı tap» (docs/architecture.md §12
 * "Room games", ADR 023): one of the level's `options` does not belong
 * with the others; choose it (`choice`). Nothing is studied and nothing is
 * shown but the options.
 *
 * - Words (`source` words): all but one from one group of
 *   `content/rooms/categories.json`, and one from another group. The
 *   validator keeps every word in exactly one group by `Az::lower`, and
 *   the builder checks it again, so the odd word is never also the
 *   group's. A word is shown as the file writes it, lowercase.
 * - Numbers (`source` numbers), 2–99: all but one share a property —
 *   even, odd, a square, a multiple of 3 to 10 — and one does not. No
 *   other property the builder knows (those, and being a prime) may single
 *   out another number, as the only one with it or the only one without
 *   it: 4, 9, 16, 12 is built again, since the squares leave 12 and the
 *   parity leaves 9.
 * - The order is drawn from the seeded source, and so are the ids
 *   (`RoomItemIds`): neither tells the odd one.
 */
final readonly class OddOneOutBuilder implements RoomRoundBuilder
{
    /** The numbers a round of numbers is drawn from: two digits at most. */
    public const int LEAST_NUMBER = 2;

    public const int MOST_NUMBER = 99;

    /** How many draws a round of numbers may take before the level is taken as one it cannot build. */
    public const int ATTEMPTS = 5_000;

    /**
     * @param  list<RoomCategoryData>  $categories  what a round of words is drawn from
     */
    public function __construct(
        private array $categories,
    ) {}

    public function build(RoomLevelData $level, int $count, Randomizer $random): array
    {
        $options = $level->options;

        if ($level->source === null || $options === null || $options < 3 || $level->studyMs !== 0) {
            throw new InvalidArgumentException('An odd_one_out level needs a source and options, and nothing to study.');
        }

        if ($level->source === RoomSource::Words) {
            $this->checkGroups($options);
        }

        $rounds = [];

        for ($index = 0; $index < $count; $index++) {
            [$shown, $odd] = $level->source === RoomSource::Words ? $this->words($options, $random) : self::numbers($options, $random);
            $rounds[] = self::built($level, $level->source->kind(), $shown, $odd, $random);
        }

        return $rounds;
    }

    /**
     * Every property a round of numbers may share or single out a number
     * by, keyed by name: the ones a round is built on, and being a prime,
     * which only rules a round out.
     *
     * @return array<string, Closure(int): bool>
     */
    public static function properties(): array
    {
        $properties = [
            'even' => static fn (int $number): bool => $number % 2 === 0,
            'square' => static fn (int $number): bool => ((int) round(sqrt($number))) ** 2 === $number,
            'prime' => static function (int $number): bool {
                if ($number < 2) {
                    return false;
                }

                for ($divisor = 2; $divisor * $divisor <= $number; $divisor++) {
                    if ($number % $divisor === 0) {
                        return false;
                    }
                }

                return true;
            },
        ];

        foreach (range(3, 10) as $factor) {
            $properties["multiple-{$factor}"] = static fn (int $number): bool => $number % $factor === 0;
        }

        return $properties;
    }

    /**
     * The index of the one number that a property singles out — the only
     * one with it or the only one without it — or null.
     *
     * @param  list<int>  $numbers
     * @param  Closure(int): bool  $property
     */
    public static function singledOut(array $numbers, Closure $property): ?int
    {
        $with = array_keys(array_filter($numbers, $property));
        $without = array_keys(array_filter($numbers, static fn (int $number): bool => ! $property($number)));

        return match (true) {
            count($with) === 1 => $with[0],
            count($without) === 1 => $without[0],
            default => null,
        };
    }

    /**
     * All but one numbers with a shared property, and the one without it,
     * which no other property singles out a different number beside.
     *
     * @return array{list<string>, int} the numbers, and the odd one's index
     */
    private static function numbers(int $options, Randomizer $random): array
    {
        $properties = self::properties();
        // What a round shares: a parity (even, or odd as "not even"), a square, a multiple.
        $shared = [['even', true], ['even', false], ['square', true]];

        foreach (range(3, 10) as $factor) {
            $shared[] = ["multiple-{$factor}", true];
        }

        [$name, $holds] = $shared[$random->getInt(0, count($shared) - 1)];
        $property = $properties[$name];
        $pool = range(self::LEAST_NUMBER, self::MOST_NUMBER);
        $with = array_values(array_filter($pool, static fn (int $number): bool => $property($number) === $holds));
        $without = array_values(array_filter($pool, static fn (int $number): bool => $property($number) !== $holds));

        if (count($with) < $options - 1 || $without === []) {
            throw new InvalidArgumentException("Too few numbers of {$name} for {$options} options.");
        }

        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            /** @var list<int> $shuffled */
            $shuffled = $random->shuffleArray($with);
            $sharing = array_slice($shuffled, 0, $options - 1);
            /** @var list<int> $numbers */
            $numbers = $random->shuffleArray([...$sharing, $without[$random->getInt(0, count($without) - 1)]]);
            $odd = self::singledOut($numbers, $property);

            if ($odd === null || in_array($numbers[$odd], $sharing, true)) {
                continue;
            }

            foreach ($properties as $other) {
                $singled = self::singledOut($numbers, $other);

                if ($singled !== null && $singled !== $odd) {
                    continue 2;
                }
            }

            return [array_map(strval(...), $numbers), $odd];
        }

        throw new InvalidArgumentException("No round of {$options} numbers that only {$name} tells apart.");
    }

    /**
     * All but one words of one group and one of another.
     *
     * @return array{list<string>, int} the words, and the odd one's index
     */
    private function words(int $options, Randomizer $random): array
    {
        /** @var list<RoomCategoryData> $groups */
        $groups = $random->shuffleArray($this->categories);
        [$own, $other] = [$groups[0], $groups[1]];

        /** @var list<string> $words */
        $words = $random->shuffleArray($own->words);
        $sharing = array_slice($words, 0, $options - 1);
        $odd = $other->words[$random->getInt(0, count($other->words) - 1)];

        /** @var list<string> $words */
        $words = $random->shuffleArray([...$sharing, $odd]);

        return [$words, (int) array_search($odd, $words, true)];
    }

    /**
     * Two groups or more, each with enough words for a round, none holding
     * a word of another.
     */
    private function checkGroups(int $options): void
    {
        $seen = [];

        foreach ($this->categories as $category) {
            if (count($category->words) < $options - 1) {
                throw new InvalidArgumentException("The group {$category->key} has too few words for {$options} options.");
            }

            foreach ($category->words as $word) {
                $key = Az::lower($word);

                if (isset($seen[$key]) && $seen[$key] !== $category->key) {
                    throw new InvalidArgumentException('A word is in two groups.');
                }

                $seen[$key] = $category->key;
            }
        }

        if (count($this->categories) < 2) {
            throw new InvalidArgumentException('A round of words needs two groups.');
        }
    }

    /**
     * @param  list<string>  $texts
     */
    private static function built(RoomLevelData $level, RoomItemKind $kind, array $texts, int $odd, Randomizer $random): BuiltRoomRoundData
    {
        $ids = new RoomItemIds($random);
        $items = array_map(static fn (string $text): RoomItemData => new RoomItemData($ids->next(), $kind, $text), $texts);

        return new BuiltRoomRoundData(
            study: null,
            ask: new RoomAskData(RoomAnswerShape::Choice, 1, RoomLayout::Row, null, $items),
            solution: new RoomSolutionData(RoomAnswerShape::Choice, [$items[$odd]->id]),
            studyMs: 0,
            answerMs: $level->answerMs,
        );
    }
}
