<?php

namespace Database\Factories;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomPlayable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Round 0 of a new room game: a `recall_order` «Asan» round the builder
 * made, not closed early. `built()` stores a round a builder returned;
 * `closedAt()` gives it an early close.
 *
 * @extends Factory<RoomRound>
 */
class RoomRoundFactory extends Factory
{
    /**
     * @var class-string<RoomRound>
     */
    protected $model = RoomRound::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $level = app(ContentRepository::class)->roomLevel(RoomMechanic::RecallOrder, 1);
        [$round] = RoomPlayable::builder(RoomMechanic::RecallOrder)->build($level, 1, new Randomizer(new Mt19937(fake()->numberBetween(1, RoomGame::MAX_SEED))));

        return [
            'room_game_id' => RoomGame::factory(),
            'position' => 0,
            ...self::columns($round),
        ];
    }

    public function built(BuiltRoomRoundData $round): static
    {
        return $this->state(fn (array $attributes) => self::columns($round));
    }

    public function closedAt(int $ms): static
    {
        return $this->state(fn (array $attributes) => [
            'closed_ms' => $ms,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function columns(BuiltRoomRoundData $round): array
    {
        return [
            'payload' => $round->payload(),
            'solution' => $round->solution->toArray(),
            'study_ms' => $round->studyMs,
            'answer_ms' => $round->answerMs,
            'closed_ms' => null,
        ];
    }
}
