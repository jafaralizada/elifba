<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomVoiceStanding;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The host's «Səsə burax», «Susdur» and «Danışmağa icazə ver» for one
 * player (docs/architecture.md §12 "Live voice", ADR 031 decision 9):
 * `PUT /rooms/{room}/members/{member}/voice` `{standing: open | muted}`.
 * Never `waiting`: nobody is sent back to wait.
 */
final readonly class RoomPlayerVoiceData
{
    public function __construct(
        public RoomVoiceStanding $standing,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'standing' => ['required', 'string', Rule::in([RoomVoiceStanding::Open->value, RoomVoiceStanding::Muted->value])],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        /** @var array{standing: string} $validated */
        $validated = Validator::make($input, self::rules())->validate();

        return new self(RoomVoiceStanding::from($validated['standing']));
    }
}
