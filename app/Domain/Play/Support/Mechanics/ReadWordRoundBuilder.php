<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
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
 * Rounds of `read_word` («Oxu və şəkli tap!»): the prompt writes the target
 * word in `prompt.show` without reading it aloud (`Sözü oxu və şəklini tap!`);
 * the items on the board are pictures of the level's words, and the target
 * word's picture solves the round. A teacher word has no picture (ADR 007)
 * and never plays here.
 */
final readonly class ReadWordRoundBuilder implements RoundBuilder
{
    public function __construct(
        private ContentRepository $content,
        private PromptVoice $voice,
    ) {}

    public function shortage(PackData $pack, LevelData $level, array $learned, ?array $letters = null): ?string
    {
        if ($level->unit !== Unit::Word || ! $level->allows(Mechanic::ReadWord)) {
            return Playable::message('level_mechanic');
        }

        $need = max($level->items ?? 0, RoundParts::MIN_TARGETS);
        $pool = self::candidates($pack, $level, $learned);

        return count($pool) < $need
            ? Playable::message('too_few_words', ['count' => $need, 'found' => count($pool)])
            : null;
    }

    public function build(GameSetupData $setup, PackData $pack, array $learned, Randomizer $random): array
    {
        RoundParts::ensurePack($setup, $pack);
        $level = $this->content->level($setup->level);

        if ($this->shortage($pack, $level, $learned) !== null) {
            throw new LogicException('There is not enough content for this game; GameSetupData::rules() should have refused it.');
        }

        $template = $this->content->prompt(Mechanic::ReadWord, Unit::Word);
        $pool = self::candidates($pack, $level, $learned);
        $decoysCount = ($level->items ?? 0) - 1;
        $audio = $this->voice->url($template, null, $pack);
        $rounds = [];

        foreach (RoundParts::deck($pool, $setup->rounds, $random) as $index => $target) {
            /** @var list<string> $others */
            $others = $random->shuffleArray(array_values(array_diff($pool, [$target])));
            /** @var list<string> $keys */
            $keys = $random->shuffleArray([$target, ...array_slice($others, 0, $decoysCount)]);
            $items = [];
            $solution = '';

            foreach ($keys as $position => $key) {
                $id = RoundParts::itemId($position);
                $word = self::word($key, $pack);
                $image = $word->image ?? throw new LogicException('A read_word item has no picture.');
                $items[] = new RoundItemData(
                    $id,
                    $setup->letter_case->apply($word->text),
                    ContentAssets::url($image, $pack->version),
                    $key,
                );

                if ($key === $target) {
                    $solution = $id;
                }
            }

            $targetWord = self::word($target, $pack);

            $rounds[] = new RoundData(
                id: (string) Str::ulid(),
                index: $index,
                total: $setup->rounds,
                mechanic: Mechanic::ReadWord,
                unit: Unit::Word,
                pack: $pack->key,
                level: $level->key,
                prompt: new PromptData(
                    text: $template->text,
                    show: $setup->letter_case->apply($targetWord->text),
                    image: null,
                    count: null,
                    audio: $audio,
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
     * @param  list<string>  $learned
     * @return list<string>
     */
    private static function candidates(PackData $pack, LevelData $level, array $learned): array
    {
        return array_values(array_filter(
            $level->keysFrom($pack, $learned),
            static fn (string $key): bool => self::word($key, $pack)->image !== null,
        ));
    }

    private static function word(string $key, PackData $pack): WordData
    {
        return $pack->word(RoundParts::value($key)) ?? throw new LogicException("The pack \"{$pack->key}\" has no such word.");
    }
}
