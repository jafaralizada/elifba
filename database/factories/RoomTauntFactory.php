<?php

namespace Database\Factories;

use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\RoomClock;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A taunt thrown now in a room game (its own game unless `for()` names
 * one), by a present player of the game's room at another; `by()` names
 * the thrower's row, `at()` the target's. A test of the cost or the state
 * sets `sent_ms` to the moment it needs.
 *
 * @extends Factory<RoomTaunt>
 */
class RoomTauntFactory extends Factory
{
    /**
     * @var class-string<RoomTaunt>
     */
    protected $model = RoomTaunt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Str::lower((string) Str::ulid()),
            'room_game_id' => RoomGame::factory(),
            'room_player_id' => fn (array $attributes) => RoomPlayer::factory()->state([
                'room_id' => RoomGame::query()->whereKey($attributes['room_game_id'])->value('room_id'),
            ]),
            'target_player_id' => fn (array $attributes) => RoomPlayer::factory()->state([
                'room_id' => RoomGame::query()->whereKey($attributes['room_game_id'])->value('room_id'),
            ]),
            'taunt' => 'turtle',
            'sent_ms' => RoomClock::nowMs(),
        ];
    }

    public function by(RoomPlayer $player): static
    {
        return $this->state(fn (array $attributes) => [
            'room_player_id' => $player->id,
        ]);
    }

    public function at(?RoomPlayer $target): static
    {
        return $this->state(fn (array $attributes) => [
            'target_player_id' => $target?->id,
        ]);
    }
}
