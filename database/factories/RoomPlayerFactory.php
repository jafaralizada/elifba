<?php

namespace Database\Factories;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A present player of a room: an account with the player role, joined now.
 * `left()` and `removed()` change that.
 *
 * @extends Factory<RoomPlayer>
 */
class RoomPlayerFactory extends Factory
{
    /**
     * @var class-string<RoomPlayer>
     */
    protected $model = RoomPlayer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'user_id' => User::factory()->player(),
            'joined_at' => now(),
            'left_at' => null,
            'removed_at' => null,
        ];
    }

    public function left(): static
    {
        return $this->state(fn (array $attributes) => [
            'left_at' => now(),
        ]);
    }

    public function removed(): static
    {
        return $this->state(fn (array $attributes) => [
            'removed_at' => now(),
        ]);
    }
}
