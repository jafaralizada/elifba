<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\RoomSequenceRule;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Support\RoomPlayable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The host's choice of a game (docs/architecture.md §12 "The race",
 * "Room games", "Games first", ADRs 023 and 026): a mechanic at a
 * difficulty — the pair is a level of `content/rooms/levels.json` — and how
 * many rounds, between `elifba.rooms.rounds.min` and `.max`. Only a level
 * whose mechanic `RoomPlayable` lists is accepted, so content may run ahead
 * of the pages. From G2 it is the room's choice (`Room::setup()`), which
 * `ChooseRoomGame` writes after `rules()` and `StartRoomGame` starts.
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
        if (! in_array($setup->mechanic->value.'.'.$setup->difficulty, self::playablePairs(), true)) {
            throw ValidationException::withMessages(['difficulty' => __('validation.in', ['attribute' => 'difficulty'])]);
        }

        return $setup;
    }

    /**
     * Whether the choice is still one a room plays: its pair is a playable
     * level and its rounds are inside the bounds — a stored choice is read
     * through it, since content and config may change under a room.
     */
    public function isPlayable(): bool
    {
        return in_array($this->mechanic->value.'.'.$this->difficulty, self::playablePairs(), true)
            && $this->rounds >= Config::integer('elifba.rooms.rounds.min')
            && $this->rounds <= Config::integer('elifba.rooms.rounds.max');
    }

    /**
     * The same difficulty and rounds with another game — «Başqa oyun», or a
     * game chosen on «Oyunlar» while the host's room is open; null when the
     * game has no level at that difficulty.
     */
    public function withMechanic(RoomMechanic $mechanic): ?self
    {
        $other = new self($mechanic, $this->difficulty, $this->rounds);

        return $other->isPlayable() ? $other : null;
    }

    /**
     * The mechanics a room plays that have levels, in the order `options()`
     * lists them: what «Oyunlar» offers and `OpenRoomData::rules()` accepts.
     *
     * @return list<RoomMechanic>
     */
    public static function mechanics(): array
    {
        $mechanics = [];

        foreach (self::playableLevels() as $level) {
            $mechanics[$level->mechanic->value] ??= $level->mechanic;
        }

        return array_values($mechanics);
    }

    /**
     * The first of `mechanics()`: the game of a room that has no other to
     * take (a room opened before G2 with none played).
     */
    public static function firstMechanic(): RoomMechanic
    {
        return self::mechanics()[0] ?? RoomPlayable::mechanics()[0];
    }

    /**
     * The difficulties a mechanic has a playable level at, lowest first.
     *
     * @return list<int>
     */
    public static function difficultiesOf(RoomMechanic $mechanic): array
    {
        $difficulties = array_map(
            static fn (RoomLevelData $level): int => $level->difficulty,
            array_filter(self::playableLevels(), static fn (RoomLevelData $level): bool => $level->mechanic === $mechanic),
        );
        sort($difficulties);

        return array_values(array_unique($difficulties));
    }

    /**
     * What the host's setup offers, as `rules()` accepts it: each mechanic
     * `RoomPlayable` lists with its family, the difficulties of its levels
     * and what each of them holds — the times, how many items (a row's
     * length, the lit cells) and a grid; for a logic game (B5) the rules,
     * terms and range of a row, or the source and options; for a word game
     * (B6) a word's letters and a spelling's swaps — and the bounds of the
     * rounds. The room page names them (docs/frontend.md rule 15); it
     * never lists them itself.
     *
     * @return array{mechanics: list<array{mechanic: string, family: string, difficulties: list<int>, levels: list<array{difficulty: int, study_ms: int, answer_ms: int, count: array{min: int, max: int}|null, grid: array{cols: int, rows: int}|null, rules: list<string>|null, terms: int|null, range: array{min: int, max: int}|null, source: string|null, options: int|null, letters: array{min: int, max: int}|null, swaps: int|null}>}>, rounds: array{min: int, max: int}}
     */
    public static function options(): array
    {
        $mechanics = [];

        foreach (self::playableLevels() as $level) {
            $mechanics[$level->mechanic->value][$level->difficulty] = $level;
        }

        $options = [];

        foreach ($mechanics as $mechanic => $levels) {
            ksort($levels);
            $options[] = [
                'mechanic' => $mechanic,
                'family' => RoomMechanic::from($mechanic)->family()->value,
                'difficulties' => array_keys($levels),
                'levels' => array_values(array_map(self::levelOption(...), $levels)),
            ];
        }

        return [
            'mechanics' => $options,
            'rounds' => ['min' => Config::integer('elifba.rooms.rounds.min'), 'max' => Config::integer('elifba.rooms.rounds.max')],
        ];
    }

    /**
     * A level as the setup describes it: `count` is how many items a round
     * asks to recall — a row's length, the lit cells — and `grid` a cell
     * game's grid; `rules`, `terms` and `range` say what a
     * `next_in_sequence` row follows, how long it is and its numbers;
     * `source` and `options` what an `odd_one_out` round offers; `letters`
     * a word game's word length and `swaps` a spelling's (1: two spellings
     * may differ in one letter; 2: any two differ in two). A key of another
     * mechanic is null.
     *
     * @return array{difficulty: int, study_ms: int, answer_ms: int, count: array{min: int, max: int}|null, grid: array{cols: int, rows: int}|null, rules: list<string>|null, terms: int|null, range: array{min: int, max: int}|null, source: string|null, options: int|null, letters: array{min: int, max: int}|null, swaps: int|null}
     */
    private static function levelOption(RoomLevelData $level): array
    {
        $count = $level->length ?? $level->lit;

        return [
            'difficulty' => $level->difficulty,
            'study_ms' => $level->studyMs,
            'answer_ms' => $level->answerMs,
            'count' => $count === null ? null : ['min' => $count->min, 'max' => $count->max],
            'grid' => $level->grid?->toArray(),
            'rules' => $level->rules === [] ? null : array_map(static fn (RoomSequenceRule $rule): string => $rule->value, $level->rules),
            'terms' => $level->terms,
            'range' => $level->range === null ? null : ['min' => $level->range->min, 'max' => $level->range->max],
            'source' => $level->source?->value,
            'options' => $level->options,
            'letters' => $level->wordLength === null ? null : ['min' => $level->wordLength->min, 'max' => $level->wordLength->max],
            'swaps' => $level->swaps,
        ];
    }

    /**
     * @return list<string> `mechanic.difficulty` of every playable level
     */
    private static function playablePairs(): array
    {
        return array_map(static fn (RoomLevelData $level): string => $level->mechanic->value.'.'.$level->difficulty, self::playableLevels());
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
