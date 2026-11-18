<?php

namespace App\Domain\Rooms\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The host's «Mən də oynayıram» (docs/architecture.md §12 "Games first",
 * ADR 026 decision 12): whether the host plays the room's games or only
 * chooses and starts them.
 */
final readonly class HostPlaysData
{
    public function __construct(
        public bool $plays,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'plays' => ['required', 'boolean'],
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

        return new self(in_array($validated['plays'], [true, 1, '1'], true));
    }
}
