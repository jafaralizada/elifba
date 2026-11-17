<?php

namespace App\Domain\Rooms\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The host's «Kilidlə» (docs/architecture.md §12 "Rooms and joining",
 * ADR 020 decision 8): whether new players may come in.
 */
final readonly class LockRoomData
{
    public function __construct(
        public bool $locked,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'locked' => ['required', 'boolean'],
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

        return new self(in_array($validated['locked'], [true, 1, '1'], true));
    }
}
