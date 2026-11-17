<?php

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Mechanics\FirstLetterRoundBuilder;
use App\Domain\Play\Support\Mechanics\ReadWordRoundBuilder;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\Voice;

beforeEach(fn () => Voice::none($this->app));

it('builds first_letter rounds with a picture in the prompt and four letters on the board', function (): void {
    $content = app(ContentRepository::class);
    $pack = $content->pack('animals');
    $setup = new GameSetupData(
        mechanic: Mechanic::FirstLetter,
        pack: 'animals',
        level: 'letters',
        rounds: 10,
        letter_case: LetterCase::Upper,
    );

    $rounds = app(FirstLetterRoundBuilder::class)->build($setup, $pack, Alphabet::letters(), new Randomizer(new Mt19937(7)));

    expect($rounds)->toHaveCount(10);

    foreach ($rounds as $index => $round) {
        $target = $round->item($round->solution->item_ids[0]);

        expect($round->index)->toBe($index)
            ->and($round->mechanic)->toBe(Mechanic::FirstLetter)
            ->and($round->unit)->toBe(Unit::Letter)
            ->and($round->items)->toHaveCount(4)
            ->and($round->solution->order)->toBe(SolutionOrder::Any)
            ->and($round->solution->item_ids)->toHaveCount(1)
            ->and($round->prompt->image)->not->toBeNull()
            ->and($round->prompt->show)->toBeNull()
            ->and($target)->not->toBeNull()
            ->and($target?->content_key)->toStartWith('letter:');
    }
});

it('builds read_word rounds with the written word in the prompt and four picture tiles on the board', function (string $level): void {
    $content = app(ContentRepository::class);
    $pack = $content->pack('animals');
    $setup = new GameSetupData(
        mechanic: Mechanic::ReadWord,
        pack: 'animals',
        level: $level,
        rounds: 8,
        letter_case: LetterCase::Lower,
    );

    $rounds = app(ReadWordRoundBuilder::class)->build($setup, $pack, Alphabet::letters(), new Randomizer(new Mt19937(11)));

    expect($rounds)->toHaveCount(8);

    foreach ($rounds as $index => $round) {
        $target = $round->item($round->solution->item_ids[0]);

        expect($round->index)->toBe($index)
            ->and($round->mechanic)->toBe(Mechanic::ReadWord)
            ->and($round->unit)->toBe(Unit::Word)
            ->and($round->items)->toHaveCount(4)
            ->and($round->solution->order)->toBe(SolutionOrder::Any)
            ->and($round->solution->item_ids)->toHaveCount(1)
            ->and($round->prompt->show)->not->toBeNull()
            ->and($round->prompt->image)->toBeNull()
            ->and($target)->not->toBeNull()
            ->and($target?->image)->not->toBeNull()
            ->and($target?->content_key)->toStartWith('word:');
    }
})->with(['words-short', 'words-long']);
