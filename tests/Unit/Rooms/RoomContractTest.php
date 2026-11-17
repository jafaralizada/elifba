<?php

use App\Domain\Rooms\Data\RoomAnswerData;
use App\Domain\Rooms\Data\RoomAnswerReceiptData;
use App\Domain\Rooms\Data\RoomStateData;

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
    ]);
});

it('has no solution in any state before the reveal', function (): void {
    $reveal = roomFixture('state.reveal');
    $study = roomFixture('state.study');
    $answer = roomFixture('state.answer');

    foreach (['state.lobby', 'state.countdown', 'state.study', 'state.answer', 'state.over'] as $name) {
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
