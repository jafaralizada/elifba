<?php

namespace Database\Factories;

use App\Domain\Rooms\Models\Room;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open room, active now, with a host who holds the player role and no
 * players yet. `closed()` and `locked()` change that; a test that needs the
 * host as a player adds the row (`RoomPlayer::factory()`) or opens the room
 * through `OpenRoom` (`Tests\Support\Rooms::open()`).
 *
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /**
     * @var class-string<Room>
     */
    protected $model = Room::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->player(),
            'code' => fake()->unique()->numerify('######'),
            'locked_at' => null,
            'revision' => 0,
            'last_activity_at' => now(),
            'closed_at' => null,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'code' => null,
            'closed_at' => now(),
        ]);
    }

    public function locked(): static
    {
        return $this->state(fn (array $attributes) => [
            'locked_at' => now(),
        ]);
    }
}
