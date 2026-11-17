<?php

namespace App\Domain\Rooms\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Opening a room (docs/architecture.md §12 "Rooms and joining", ADR 020):
 * only whether the host plays in it — by default the host is the room's
 * first player. A room has no name, title or anything else typed.
 */
final readonly class OpenRoomData
{
    public function __construct(
        public bool $plays = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'plays' => ['sometimes', 'boolean'],
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

        return new self(! array_key_exists('plays', $validated) || in_array($validated['plays'], [true, 1, '1'], true));
    }
}
