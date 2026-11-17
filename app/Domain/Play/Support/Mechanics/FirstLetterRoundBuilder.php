<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\PromptData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Data\SolutionData;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Playable;
use App\Domain\Play\Support\PromptVoice;
use Illuminate\Support\Str;
use LogicException;
use Random\Randomizer;

/**
 * Rounds of `first_letter` («İlk hərfi tap!»): the prompt shows a word's
 * picture and names the word (`«{x}» hansı hərflə başlayır?`); the items on
 * the board are letters from the learned letters, and the word's first
 * letter solves the round.
 */
final readonly class FirstLetterRoundBuilder implements RoundBuilder
{
    public function __construct(
        private ContentRepository $content,
        private PromptVoice $voice,
    ) {}

    public function shortage(PackData $pack, LevelData $level, array $learned, ?array $letters = null): ?string
    {
        if ($level->unit !== Unit::Letter || ! $level->allows(Mechanic::FirstLetter)) {
            return Playable::message('level_mechanic');
        }

        $pictured = $pack->pictured();

        if (count($pictured) < RoundParts::MIN_TARGETS) {
            return Playable::message('too_few_pictures', ['count' => RoundParts::MIN_TARGETS, 'found' => count($pictured)]);
        }

        $need = $level->items ?? 0;
        $pool = Alphabet::sort($learned);

        if (count($pool) < $need) {
            return Playable::message('too_few_letters', ['count' => $need]);
        }

        $allowed = $letters === null ? $pool : array_values(array_intersect($pool, $letters));

        if ($letters !== null && count($allowed) < RoundParts::MIN_TARGETS) {
            return Playable::message('too_few_targets', ['count' => RoundParts::MIN_TARGETS]);
        }

        $words = self::wordsStartingWith($pictured, $allowed);

        if (count($words) < RoundParts::MIN_TARGETS) {
            return $letters !== null
                ? Playable::message('too_few_targets', ['count' => RoundParts::MIN_TARGETS])
                : Playable::message('too_few_letters', ['count' => $need]);
        }

        return null;
    }

    public function build(GameSetupData $setup, PackData $pack, array $learned, Randomizer $random): array
    {
        RoundParts::ensurePack($setup, $pack);
        $level = $this->content->level($setup->level);

        if ($this->shortage($pack, $level, $learned, $setup->letters) !== null) {
            throw new LogicException('There is not enough content for this game; GameSetupData::rules() should have refused it.');
        }

        $template = $this->content->prompt(Mechanic::FirstLetter, Unit::Letter);
        $pool = array_map(static fn (string $letter): string => "letter:{$letter}", Alphabet::sort($learned));
        $allowed = $setup->letters === null ? Alphabet::sort($learned) : array_values(array_intersect(Alphabet::sort($learned), $setup->letters));
        $chosen = $setup->letters === null ? null : array_map(static fn (string $letter): string => "letter:{$letter}", $allowed);
        $words = self::wordsStartingWith($pack->pictured(), $allowed);
        $wordKeys = array_map(static fn (WordData $word): string => $word->key(), $words);
        $decoysCount = ($level->items ?? 0) - 1;
        $rounds = [];

        foreach (RoundParts::deck($wordKeys, $setup->rounds, $random) as $index => $wordKey) {
            $word = $pack->word(RoundParts::value($wordKey)) ?? throw new LogicException('A pictured word is not in the pack.');
            $image = $word->image ?? throw new LogicException('A first_letter target has no picture.');
            $first = Az::lower(mb_substr($word->text, 0, 1));
            $target = "letter:{$first}";
            /** @var list<string> $keys */
            $keys = $random->shuffleArray([$target, ...self::decoys($target, $pool, $chosen, $decoysCount, $random)]);
            $items = [];
            $solution = '';

            foreach ($keys as $position => $key) {
                $id = RoundParts::itemId($position);
                $letter = RoundParts::value($key);
                $items[] = new RoundItemData($id, $setup->letter_case->apply($letter), null, $key);

                if ($key === $target) {
                    $solution = $id;
                }
            }

            $rounds[] = new RoundData(
                id: (string) Str::ulid(),
                index: $index,
                total: $setup->rounds,
                mechanic: Mechanic::FirstLetter,
                unit: Unit::Letter,
                pack: $pack->key,
                level: $level->key,
                prompt: new PromptData(
                    text: $template->fill($word->text),
                    show: null,
                    image: ContentAssets::url($image, $pack->version),
                    count: null,
                    audio: $this->voice->url($template, $wordKey, $pack),
                    tail: null,
                ),
                items: $items,
                slots: 0,
                solution: new SolutionData(SolutionOrder::Any, [$solution]),
                effect: $pack->style->effect,
            );
        }

        return $rounds;
    }

    /**
     * Pictured words whose first letter is in `$allowed`.
     *
     * @param  list<WordData>  $pictured
     * @param  list<string>  $allowed
     * @return list<WordData>
     */
    private static function wordsStartingWith(array $pictured, array $allowed): array
    {
        return array_values(array_filter(
            $pictured,
            static fn (WordData $word): bool => in_array(Az::lower(mb_substr($word->text, 0, 1)), $allowed, true),
        ));
    }

    /**
     * @param  list<string>  $pool
     * @param  list<string>|null  $chosen
     * @return list<string>
     */
    private static function decoys(string $target, array $pool, ?array $chosen, int $count, Randomizer $random): array
    {
        $confusables = array_map(
            static fn (string $letter): string => "letter:{$letter}",
            Alphabet::confusables(RoundParts::value($target)),
        );
        $first = [];

        if ($chosen !== null) {
            $others = array_values(array_diff($chosen, [$target]));
            $near = array_values(array_intersect($confusables, $others));
            /** @var list<string> $shuffledChosen */
            $shuffledChosen = $random->shuffleArray(array_values(array_diff($others, $near)));
            $first = [...$near, ...$shuffledChosen];
        }

        $near = array_values(array_diff(array_intersect($confusables, $pool), $first));
        /** @var list<string> $rest */
        $rest = $random->shuffleArray(array_values(array_diff($pool, [$target], $first, $near)));

        return array_slice([...$first, ...$near, ...$rest], 0, $count);
    }
}
