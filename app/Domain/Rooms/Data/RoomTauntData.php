<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Content\Support\ContentRepository;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A taunt as the thrower's device posts it (docs/architecture.md §12
 * "Taunts (T2)", ADR 029 decision 13; zod `RoomTaunt`, fixture
 * tests/Fixtures/rooms/taunt.json): `POST /rooms/{room}/taunts`
 * `{event_id, target_id, taunt}`. The event id is the device's own ULID,
 * so a taunt sent twice is stored and paid for once; the target is a
 * `room_players.id`; the taunt is a key of `content/rooms/taunts.json` —
 * never text. Nothing names the thrower: the thrower is the session's
 * account (`ThrowRoomTaunt`).
 *
 * The ids are read in lowercase, as the rows store them.
 *
 * @phpstan-type RoomTauntArray array{event_id: string, target_id: string, taunt: string}
 */
final readonly class RoomTauntData
{
    public function __construct(
        public string $event_id,
        public string $target_id,
        public string $taunt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'ulid'],
            'target_id' => ['required', 'string', 'ulid'],
            'taunt' => ['required', 'string', Rule::in(array_keys(app(ContentRepository::class)->roomTaunts()))],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        /** @var RoomTauntArray $validated */
        $validated = Validator::make($input, self::rules())->validate();

        return self::fromArray($validated);
    }

    /**
     * @param  RoomTauntArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Str::lower($data['event_id']), Str::lower($data['target_id']), $data['taunt']);
    }

    /**
     * @return RoomTauntArray
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->event_id,
            'target_id' => $this->target_id,
            'taunt' => $this->taunt,
        ];
    }
}
