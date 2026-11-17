<?php

namespace Database\Factories;

use App\Domain\Rooms\Models\RoomAnswer;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomPointsRule;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A right answer to a room round, 2.5 s after the question opened, by a
 * present player of the round's room, with the points the rule gives it.
 * `wrong()` makes it a wrong one; `by()` names the player's row.
 *
 * @extends Factory<RoomAnswer>
 */
class RoomAnswerFactory extends Factory
{
    /**
     * @var class-string<RoomAnswer>
     */
    protected $model = RoomAnswer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => Str::lower((string) Str::ulid()),
            'room_round_id' => RoomRound::factory(),
            'room_player_id' => fn (array $attributes) => RoomPlayer::factory()->state([
                'room_id' => self::round($attributes)->game->room_id,
            ]),
            'picked' => fn (array $attributes): array => self::round($attributes)->solutionData()->ids,
            'correct' => true,
            'elapsed_ms' => 2_500,
            'points' => fn (array $attributes): int => RoomPointsRule::points(
                (bool) $attributes['correct'],
                (int) $attributes['elapsed_ms'],
                self::round($attributes)->answer_ms,
            ),
            'created_at' => now(),
        ];
    }

    public function by(RoomPlayer $player): static
    {
        return $this->state(fn (array $attributes) => [
            'room_player_id' => $player->id,
        ]);
    }

    /**
     * The solution's ids turned by one place: a wrong order.
     */
    public function wrong(): static
    {
        return $this->state(fn (array $attributes) => [
            'picked' => function (array $attributes): array {
                $ids = self::round($attributes)->solutionData()->ids;

                return [...array_slice($ids, 1), $ids[0]];
            },
            'correct' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function round(array $attributes): RoomRound
    {
        return RoomRound::query()->with('game')->whereKey($attributes['room_round_id'])->firstOrFail();
    }
}
