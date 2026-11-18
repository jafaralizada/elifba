<?php

use App\Domain\Rooms\Data\RoomAnswerData;
use App\Domain\Rooms\Data\RoomAnswerReceiptData;
use App\Domain\Rooms\Data\RoomStateData;
use App\Domain\Rooms\Data\RoomTauntData;
use App\Domain\Rooms\Data\RoomTauntReceiptData;

/*
 * The room's contract on the PHP side (docs/architecture.md §12 "Room
 * games", "The state"): every fixture of tests/Fixtures/rooms — the ones
 * resources/js/rooms/contract.test.ts parses with the zod schemas — goes
 * through its DTO and comes back byte for byte, and the DTOs refuse what
 * the zod schemas refuse: a key of the reveal, the material or the
 * question in a phase that may not serve it. judge.json is the judge's
 * (RoomJudgeTest), not a payload.
 */

const ROOM_FIXTURES = __DIR__.'/../../Fixtures/rooms';

/**
 * @return array<string, mixed>
 */
function roomFixture(string $name): array
{
    $decoded = json_decode((string) file_get_contents(ROOM_FIXTURES."/{$name}.json"), true, 64, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : throw new RuntimeException("{$name} is not an object.");
}

/**
 * Every fixture a device receives or sends, by name.
 *
 * @return array<string, string>
 */
function roomPayloads(): array
{
    $names = array_map(static fn (string $path): string => basename($path, '.json'), glob(ROOM_FIXTURES.'/*.json') ?: []);
    $names = array_values(array_filter($names, static fn (string $name): bool => $name !== 'judge'));

    return array_combine($names, $names);
}

it('round-trips every fixture', function (string $name): void {
    $fixture = roomFixture($name);

    $again = match (strstr($name, '.', true) ?: $name) {
        'state' => RoomStateData::fromArray($fixture)->toArray(),
        'answer' => RoomAnswerData::fromArray($fixture)->toArray(),
        'receipt' => RoomAnswerReceiptData::fromArray($fixture)->toArray(),
        'taunt' => RoomTauntData::fromArray($fixture)->toArray(),
        'taunt-receipt' => RoomTauntReceiptData::fromArray($fixture)->toArray(),
    };

    expect($again)->toBe($fixture);
})->with(roomPayloads());

it('has every phase\'s fixture, the answer and the receipt', function (): void {
    expect(array_keys(roomPayloads()))->toEqualCanonicalizing([
        'state.lobby', 'state.countdown', 'state.study', 'state.answer', 'state.reveal', 'state.over', 'answer', 'receipt',
        // B4: a grid of cells to study, answer and reveal, and a row of shapes.
        'state.study.cells', 'state.answer.cells', 'state.reveal.cells', 'state.study.shapes',
        // B5: a row to continue and an odd one to find, asked and revealed.
        'state.answer.sequence', 'state.reveal.sequence', 'state.answer.odd', 'state.reveal.odd',
        // B6: each word game's question, answer and reveal.
        'state.answer.anagram', 'answer.anagram', 'state.reveal.anagram',
        'state.answer.spelling', 'answer.spelling', 'state.reveal.spelling',
        // G2: a game over and another chosen.
        'state.chosen',
        // T2: a target's phone and the screen with taunts, a taunt and its receipt.
        'state.taunted', 'state.screen-taunted', 'taunt', 'taunt-receipt',
    ]);
});

it('has no solution in any state before the reveal', function (): void {
    $reveal = roomFixture('state.reveal');
    $study = roomFixture('state.study');
    $answer = roomFixture('state.answer');

    foreach (['state.lobby', 'state.countdown', 'state.study', 'state.answer', 'state.over', 'state.chosen'] as $name) {
        $wire = (string) json_encode(roomFixture($name));

        expect($wire)->not->toMatch('/"(solution|accept|correct|mine|results|picked|elapsed_ms|seed)"/', $name)
            ->and($wire)->not->toContain(json_encode($reveal['round']['reveal']['solution']), $name);
    }

    // A grid: neither the study's ids nor the solution's order are in the question before the reveal.
    $cells = roomFixture('state.reveal.cells');

    foreach (['state.study.cells', 'state.answer.cells', 'state.study.shapes'] as $name) {
        $wire = (string) json_encode(roomFixture($name));

        expect($wire)->not->toMatch('/"(solution|accept|correct|mine|results|picked|elapsed_ms|seed)"/', $name)
            ->and($wire)->not->toContain(json_encode($cells['round']['reveal']['solution']), $name);
    }

    // A word game: the word only in the reveal; an anagram's tiles never in the order of a word they spell.
    foreach (['anagram', 'spelling'] as $game) {
        $revealed = roomFixture("state.reveal.{$game}")['round']['reveal'];
        $wire = (string) json_encode(roomFixture("state.answer.{$game}"), JSON_UNESCAPED_UNICODE);

        expect($revealed['word'])->not->toBeNull()
            ->and($wire)->not->toMatch('/"(solution|accept|correct|mine|results|picked|elapsed_ms|seed|word)":/', $game)
            ->and($wire)->not->toContain(json_encode($revealed['solution']));
    }

    expect(implode('', array_column(roomFixture('state.answer.anagram')['round']['ask']['items'], 'text')))->not->toBe('ALMA')
        ->and(array_column(roomFixture('state.answer.spelling')['round']['ask']['items'], 'text'))->toContain('kitab');

    $studied = array_column(roomFixture('state.study.cells')['round']['study']['items'], 'id');
    $asked = roomFixture('state.answer.cells')['round']['ask']['items'];

    expect(array_intersect($studied, array_column($asked, 'id')))->toBe([])
        ->and(array_column($asked, 'cell'))->toBe([[0, 0], [1, 0], [2, 0], [0, 1], [1, 1], [2, 1], [0, 2], [1, 2], [2, 2]]);

    // The DTOs refuse a key of a later or an earlier phase, as the zod schemas do.
    $refused = static function (array $state, array $round, string $why): void {
        expect(fn () => RoomStateData::fromArray([...$state, 'round' => [...$state['round'], ...$round]]))
            ->toThrow(InvalidArgumentException::class, $why);
    };

    foreach (['state.countdown', 'state.study', 'state.answer'] as $name) {
        $state = roomFixture($name);
        $refused($state, ['reveal' => $reveal['round']['reveal']], 'reveal');
        $refused($state, ['closes_ms' => $reveal['round']['closes_ms'], 'next_ms' => $reveal['round']['next_ms']], 'close');
    }

    $refused($answer, ['study' => $study['round']['study']], 'studied');
    $refused($study, ['ask' => $answer['round']['ask']], 'question');
    $refused(roomFixture('state.countdown'), ['ask' => $answer['round']['ask']], 'question');
    $refused($study, ['answered' => true, 'answered_count' => 1], 'Nobody answers');
    $refused($reveal, ['reveal' => null], 'reveal');

    // A round only while a game is live.
    expect(fn () => RoomStateData::fromArray([...roomFixture('state.over'), 'round' => $answer['round']]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RoomStateData::fromArray([...$answer, 'game' => null]))->toThrow(InvalidArgumentException::class)
        ->and(fn () => RoomStateData::fromArray([...$answer, 'round' => null]))->toThrow(InvalidArgumentException::class);
});

it('says the choice of the next game in every open room\'s state, and nothing else in it', function (): void {
    foreach (array_filter(array_keys(roomPayloads()), static fn (string $name): bool => str_starts_with($name, 'state.')) as $name) {
        $state = roomFixture($name);

        expect($state['v'])->toBe(RoomStateData::VERSION)
            ->and(array_keys($state['setup']))->toBe(['mechanic', 'difficulty', 'rounds', 'played'], $name)
            // Played only once the game is over and was this very choice.
            ->and($state['setup']['played'])->toBe($state['game'] !== null && $state['game']['phase'] === 'over'
                && [$state['game']['mechanic'], $state['game']['difficulty'], $state['game']['rounds']] === [$state['setup']['mechanic'], $state['setup']['difficulty'], $state['setup']['rounds']], $name);
    }

    expect(roomFixture('state.over')['setup']['played'])->toBeTrue()
        ->and(roomFixture('state.chosen')['setup'])->toBe(['mechanic' => 'anagram', 'difficulty' => 2, 'rounds' => 5, 'played' => false]);

    // An open room has a choice and a closed one none; a state of another version is refused.
    $lobby = roomFixture('state.lobby');
    $closed = [...$lobby, 'room' => [...$lobby['room'], 'status' => 'closed', 'code' => null]];

    expect(fn () => RoomStateData::fromArray([...$lobby, 'setup' => null]))->toThrow(InvalidArgumentException::class, 'choice')
        ->and(fn () => RoomStateData::fromArray($closed))->toThrow(InvalidArgumentException::class, 'choice')
        ->and(RoomStateData::fromArray([...$closed, 'setup' => null])->setup)->toBeNull()
        ->and(fn () => RoomStateData::fromArray([...$lobby, 'v' => 1]))->toThrow(InvalidArgumentException::class, 'version');
});

it('carries a live game\'s taunts, and nothing of them anywhere else', function (): void {
    foreach (array_filter(array_keys(roomPayloads()), static fn (string $name): bool => str_starts_with($name, 'state.')) as $name) {
        $state = roomFixture($name);
        $live = $state['game'] !== null && $state['game']['phase'] === 'live';

        expect(array_keys($state['room']))->toBe(['id', 'status', 'locked', 'code', 'taunts', 'screen'], $name)
            ->and($state['taunts'] === null)->toBe(! $live, $name);

        if ($live) {
            expect(array_keys($state['taunts']))->toBe(['open', 'balance', 'ready_ms', 'received', 'shown'], $name);

            // The target's own list never names a target; only the screen's does.
            foreach ($state['taunts']['received'] as $taunt) {
                expect(array_keys($taunt))->toBe(['id', 'from', 'taunt', 'sent_ms'], $name);
            }

            foreach ($state['taunts']['shown'] ?? [] as $taunt) {
                expect(array_keys($taunt))->toBe(['id', 'from', 'to', 'taunt', 'sent_ms'], $name);
            }
        }

        // The cost of a close only in its reveal.
        if ($state['round'] !== null && $state['round']['reveal'] !== null) {
            expect(array_keys($state['round']['reveal']))->toBe(['solution', 'word', 'mine', 'results', 'spent'], $name);
        }
    }

    expect(roomFixture('state.taunted')['taunts']['received'])->toHaveCount(1)
        ->and(roomFixture('state.screen-taunted')['taunts']['shown'])->toHaveCount(2)
        ->and(roomFixture('state.reveal')['round']['reveal']['spent'])->toBe([['player_id' => '01kaqrx2ab3c4d5e6f7g8h9jkm', 'count' => 1]]);

    // A live game has its taunts and nothing else has; a received taunt never names its target, a shown one always does.
    $answer = roomFixture('state.answer');
    $taunted = roomFixture('state.taunted');
    $screen = roomFixture('state.screen-taunted');

    expect(fn () => RoomStateData::fromArray([...$answer, 'taunts' => null]))->toThrow(InvalidArgumentException::class, 'taunts')
        ->and(fn () => RoomStateData::fromArray([...roomFixture('state.over'), 'taunts' => $answer['taunts']]))->toThrow(InvalidArgumentException::class, 'taunts')
        ->and(fn () => RoomStateData::fromArray([...$answer, 'taunts' => [...$taunted['taunts'], 'received' => $screen['taunts']['shown']]]))->toThrow(InvalidArgumentException::class, 'target')
        ->and(fn () => RoomStateData::fromArray([...$answer, 'taunts' => [...$screen['taunts'], 'shown' => $taunted['taunts']['received']]]))->toThrow(InvalidArgumentException::class, 'target')
        ->and(fn () => RoomStateData::fromArray([...$answer, 'taunts' => [...$answer['taunts'], 'balance' => -1]]))->toThrow(InvalidArgumentException::class, 'below 0');
});
