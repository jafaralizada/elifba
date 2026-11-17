<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Support\RoomCode;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A room code as a player typed it at `/otaq` (docs/architecture.md §12
 * "Rooms and joining"): spaces and dashes are dropped, and anything but six
 * digits is an error on `code`. Whether a room has the code is not this
 * check's to say: an unknown code is a 404 at `/otaq/{code}`.
 */
final readonly class RoomCodeData
{
    public function __construct(
        public string $code,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'code' => ['required', 'string', 'regex:/^\d{'.RoomCode::DIGITS.'}$/D'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $message = __('rooms.errors.code');

        return [
            'code.required' => is_string($message) ? $message : 'code',
            'code.string' => is_string($message) ? $message : 'code',
            'code.regex' => is_string($message) ? $message : 'code',
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function prepare(array $input): array
    {
        $code = $input['code'] ?? null;

        if (is_string($code) || is_int($code)) {
            $input['code'] = RoomCode::normalize($code) ?? (string) $code;
        }

        return $input;
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $validated = Validator::make(self::prepare($input), self::rules(), self::messages())->validate();

        return new self((string) $validated['code']);
    }
}
