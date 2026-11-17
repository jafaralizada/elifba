<?php

use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use App\Domain\Rooms\Support\RoomPlayable;

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

    expect(RoomPlayable::mechanics())->toBe([RoomMechanic::RecallOrder]);
});
