<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Support\RoomPlayable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The host's choice of a game (docs/architecture.md §12 "The race",
 * "Room games", ADR 023): a mechanic at a difficulty — the pair is a level
 * of `content/rooms/levels.json` — and how many rounds, between
 * `elifba.rooms.rounds.min` and `.max`. Only a level whose mechanic
 * `RoomPlayable` lists is accepted, so content may run ahead of the pages.
 */
final readonly class RoomGameSetupData
{
    public function __construct(
        public RoomMechanic $mechanic,
        public int $difficulty,
        public int $rounds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        $levels = self::playableLevels();

        return [
            'mechanic' => ['required', 'string', Rule::in(array_values(array_unique(array_map(static fn (RoomLevelData $level): string => $level->mechanic->value, $levels))))],
            'difficulty' => ['required', 'integer', Rule::in(array_values(array_unique(array_map(static fn (RoomLevelData $level): int => $level->difficulty, $levels))))],
            'rounds' => ['required', 'integer', 'between:'.Config::integer('elifba.rooms.rounds.min').','.Config::integer('elifba.rooms.rounds.max')],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        /** @var array{mechanic: string, difficulty: int|string, rounds: int|string} $validated */
        $validated = Validator::make($input, self::rules())->validate();
        $setup = new self(RoomMechanic::from($validated['mechanic']), (int) $validated['difficulty'], (int) $validated['rounds']);

        // Each value may be a level's while the pair is none.
        $pairs = array_map(static fn (RoomLevelData $level): string => $level->mechanic->value.'.'.$level->difficulty, self::playableLevels());

        if (! in_array($setup->mechanic->value.'.'.$setup->difficulty, $pairs, true)) {
            throw ValidationException::withMessages(['difficulty' => __('validation.in', ['attribute' => 'difficulty'])]);
        }

        return $setup;
    }

    /**
     * What the host's setup offers, as `rules()` accepts it: each mechanic
     * `RoomPlayable` lists with the difficulties of its levels, and the
     * bounds of the rounds. The room page names them; it never lists them
     * itself.
     *
     * @return array{mechanics: list<array{mechanic: string, difficulties: list<int>}>, rounds: array{min: int, max: int}}
     */
    public static function options(): array
    {
        $mechanics = [];

        foreach (self::playableLevels() as $level) {
            $mechanics[$level->mechanic->value][] = $level->difficulty;
        }

        $options = [];

        foreach ($mechanics as $mechanic => $difficulties) {
            sort($difficulties);
            $options[] = ['mechanic' => $mechanic, 'difficulties' => $difficulties];
        }

        return [
            'mechanics' => $options,
            'rounds' => ['min' => Config::integer('elifba.rooms.rounds.min'), 'max' => Config::integer('elifba.rooms.rounds.max')],
        ];
    }

    /**
     * @return list<RoomLevelData>
     */
    private static function playableLevels(): array
    {
        return array_values(array_filter(
            app(ContentRepository::class)->roomLevels(),
            static fn (RoomLevelData $level): bool => RoomPlayable::plays($level->mechanic),
        ));
    }
}
