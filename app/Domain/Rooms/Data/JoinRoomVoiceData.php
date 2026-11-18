<?php

namespace App\Domain\Rooms\Data;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * A request for a ticket to a room's live voice (docs/architecture.md §12
 * "Live voice", ADR 031 decisions 14 and 19): `POST
 * /rooms/{room}/voice/tickets` `{agree?: true}`. An account that has not
 * accepted the voice's sentence must accept it with this request; one that
 * has may leave it out.
 */
final readonly class JoinRoomVoiceData
{
    public function __construct(
        public bool $agree,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(User $account): array
    {
        return [
            'agree' => $account->voice_agreed_at === null ? ['required', 'accepted'] : ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException on `agree`, with `rooms.voice.errors.agreement`
     */
    public static function validate(array $input, User $account): self
    {
        $message = __('rooms.voice.errors.agreement');
        $validated = Validator::make($input, self::rules($account), [
            'agree.required' => $message,
            'agree.accepted' => $message,
        ])->validate();

        return new self(in_array($validated['agree'] ?? false, [true, 1, '1', 'yes', 'on'], true));
    }
}
