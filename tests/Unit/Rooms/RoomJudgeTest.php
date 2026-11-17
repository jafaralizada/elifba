<?php

use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Data\RoomSolutionData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Support\RoomJudge;

/*
 * The judge of a room answer (docs/architecture.md §12 "Room games", ADR
 * 023 decision 4), PHP only: the cases of tests/Fixtures/rooms/judge.json
 * for the three shapes. A sequence is judged by what the items say, so
 * two tiles that say the same are interchangeable.
 */

/**
 * @return array<string, array{list<RoomItemData>, RoomSolutionData, list<string>, bool}>
 */
function roomJudgeCases(): array
{
    /** @var array{cases: list<array{name: string, items: list<array{id: string, kind: string, text: string|null, asset: string|null, cell: array{int, int}|null}>, solution: array{shape: string, ids: list<string>, accept?: list<list<string>>}, picked: list<string>, correct: bool}>} $decoded */
    $decoded = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/rooms/judge.json'), true, 64, JSON_THROW_ON_ERROR);
    $cases = [];

    foreach ($decoded['cases'] as $case) {
        $cases[$case['name']] = [
            array_map(RoomItemData::fromArray(...), $case['items']),
            RoomSolutionData::fromArray($case['solution']),
            $case['picked'],
            $case['correct'],
        ];
    }

    return $cases;
}

it('judges a choice, a set and a sequence from the shared cases', function (array $items, RoomSolutionData $solution, array $picked, bool $correct): void {
    expect(RoomJudge::correct($solution, $items, $picked))->toBe($correct);
})->with(roomJudgeCases());

it('has a right and a wrong case for every shape', function (): void {
    $seen = [];

    foreach (roomJudgeCases() as [, $solution, , $correct]) {
        $seen[$solution->shape->value][$correct ? 'right' : 'wrong'] = true;
    }

    foreach (RoomAnswerShape::cases() as $shape) {
        expect($seen[$shape->value] ?? [])->toBe(['right' => true, 'wrong' => true], $shape->value);
    }
});

it('takes tiles that say the same as interchangeable', function (): void {
    // The row «Ə L Ə M»: the two «Ə» tiles may be tapped either way round.
    $items = [
        new RoomItemData('aa11bb22', RoomItemKind::Letter, 'M'),
        new RoomItemData('cc33dd44', RoomItemKind::Letter, 'Ə'),
        new RoomItemData('ee55ff66', RoomItemKind::Letter, 'L'),
        new RoomItemData('gg77hh88', RoomItemKind::Letter, 'Ə'),
    ];
    $solution = new RoomSolutionData(RoomAnswerShape::Sequence, ['cc33dd44', 'ee55ff66', 'gg77hh88', 'aa11bb22'], [['Ə', 'L', 'Ə', 'M']]);

    expect(RoomJudge::correct($solution, $items, ['cc33dd44', 'ee55ff66', 'gg77hh88', 'aa11bb22']))->toBeTrue()
        ->and(RoomJudge::correct($solution, $items, ['gg77hh88', 'ee55ff66', 'cc33dd44', 'aa11bb22']))->toBeTrue()
        ->and(RoomJudge::correct($solution, $items, ['cc33dd44', 'ee55ff66', 'cc33dd44', 'aa11bb22']))->toBeFalse()
        ->and(RoomJudge::correct($solution, $items, ['ee55ff66', 'cc33dd44', 'gg77hh88', 'aa11bb22']))->toBeFalse();

    // A set and a choice compare ids: an item that says the same is another item.
    $set = new RoomSolutionData(RoomAnswerShape::Set, ['cc33dd44']);
    $choice = new RoomSolutionData(RoomAnswerShape::Choice, ['cc33dd44']);

    expect(RoomJudge::correct($set, $items, ['gg77hh88']))->toBeFalse()
        ->and(RoomJudge::correct($choice, $items, ['gg77hh88']))->toBeFalse();
});
