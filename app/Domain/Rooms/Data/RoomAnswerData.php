<?php

namespace App\Domain\Rooms\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A player's answer as the device posts it (docs/architecture.md §12 "The
 * race", ADR 021 decision 1; zod `RoomAnswer`, fixture
 * tests/Fixtures/rooms/answer.json): `POST /rooms/{room}/answers`
 * `{event_id, round_id, picked}`. The event id is the device's own ULID, so
 * an answer sent twice is stored once; `picked` is item ids of the round's
 * question in the order tapped — a player never types. Nothing names a
 * player: the player is the session's account (`RecordRoomAnswer`).
 *
 * The ids are read in lowercase, as the rows store them.
 *
 * @phpstan-type RoomAnswerArray array{event_id: string, round_id: string, picked: list<string>}
 */
final readonly class RoomAnswerData
{
    /** The most items an answer may hold: a 6 × 6 grid. */
    public const int MAX_PICKED = 36;

    /**
     * @param  list<string>  $picked
     */
    public function __construct(
        public string $event_id,
        public string $round_id,
        public array $picked,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'ulid'],
            'round_id' => ['required', 'string', 'ulid'],
            'picked' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_PICKED],
            'picked.*' => ['required', 'string', 'regex:/^[a-z0-9]{8}$/'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        // The size first: the rule for each item would run over every one of a huge list.
        Validator::make($input, ['picked' => self::rules()['picked']])->validate();

        /** @var array{event_id: string, round_id: string, picked: list<string>} $validated */
        $validated = Validator::make($input, self::rules())->validate();

        return self::fromArray($validated);
    }

    /**
     * @param  RoomAnswerArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Str::lower($data['event_id']), Str::lower($data['round_id']), $data['picked']);
    }

    /**
     * @return RoomAnswerArray
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->event_id,
            'round_id' => $this->round_id,
            'picked' => $this->picked,
        ];
    }
}
