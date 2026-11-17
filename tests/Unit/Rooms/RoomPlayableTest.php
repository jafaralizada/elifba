<?php

use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use App\Domain\Rooms\Support\RoomPlayable;
use App\Domain\Rooms\Support\RoomSeed;
use Illuminate\Support\Facades\Config;

/*
 * What a room can play (docs/architecture.md §12 "Room games", "Room
 * content"): the validator, which may not name Rooms, gives a mechanic all
 * four difficulties or none; this test gives every mechanic RoomPlayable
 * lists its levels, so it plays at every difficulty, and a builder.
 */

it('lists only mechanics with all four levels', function (): void {
    $content = app(ContentRepository::class);

    expect(RoomPlayable::mechanics())->not->toBeEmpty();

    foreach (RoomPlayable::mechanics() as $mechanic) {
        $difficulties = array_map(
            static fn (RoomLevelData $level): int => $level->difficulty,
            array_values(array_filter($content->roomLevels(), static fn (RoomLevelData $level): bool => $level->mechanic === $mechanic)),
        );
        sort($difficulties);

        expect($difficulties)->toBe(range(1, ContentValidator::ROOM_DIFFICULTIES), $mechanic->value)
            ->and(RoomPlayable::plays($mechanic))->toBeTrue();
    }
});

it('has a builder for every mechanic', function (): void {
    foreach (RoomMechanic::cases() as $mechanic) {
        expect(RoomPlayable::builder($mechanic))->not->toBeNull();
    }

    expect(RoomPlayable::mechanics())->toBe([RoomMechanic::RecallOrder, RoomMechanic::RecallCells, RoomMechanic::NextInSequence, RoomMechanic::OddOneOut]);
});

// The validator may not reach a builder's arithmetic — whether a rule of
// `next_in_sequence` fits its range, say — so every level of the content
// is built here, a full game of the most rounds from several seeds.
it('builds a game at every level of every mechanic it lists', function (): void {
    $content = app(ContentRepository::class);

    foreach (RoomPlayable::mechanics() as $mechanic) {
        foreach (range(1, ContentValidator::ROOM_DIFFICULTIES) as $difficulty) {
            $level = $content->roomLevel($mechanic, $difficulty);

            foreach (range(1, 5) as $seed) {
                $rounds = RoomPlayable::builder($mechanic)->build($level, Config::integer('elifba.rooms.rounds.max'), RoomSeed::random($seed));

                expect($rounds)->toHaveCount(Config::integer('elifba.rooms.rounds.max'));

                foreach ($rounds as $round) {
                    expect($round->studyMs)->toBe($level->studyMs)
                        ->and($round->study === null)->toBe(! $mechanic->studies());
                }
            }
        }
    }
});
