<?php

namespace App\Domain\Rooms\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The host's switch «Atmacalar» (docs/architecture.md §12 "Taunts (T2)",
 * ADR 029 decision 12): `PUT /rooms/{room}/taunts` `{on}`, whether the
 * room's players may throw taunts.
 */
final readonly class RoomTauntsSwitchData
{
    public function __construct(
        public bool $on,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'on' => ['required', 'boolean'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $validated = Validator::make($input, self::rules())->validate();

        return new self(in_array($validated['on'], [true, 1, '1'], true));
    }
}
