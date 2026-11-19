<?php

namespace App\Actions\Fortify;

use App\Actions\Roles\GrantRole;
use App\Concerns\ProfileValidationRules;
use App\Data\RoleChoiceData;
use App\Enums\Role;
use App\Exceptions\GoogleSignInRefused;
use App\Models\User;
use App\Support\GoogleProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates the account of a teacher who signed up with Google (ADR 008), from
 * the completion page: the name (Google's, editable) and the same privacy-note
 * agreement as registration (docs/architecture.md §7). Google verified the
 * address; there is no password until the teacher sets one.
 *
 * While a room's invitation is under way (`$invited`, §12 "The invitation
 * link (I2)", ADR 033 decision 13) the page also asks for the player's
 * sentence, a tick of its own beside the note's, and the account takes the
 * player role in the same transaction, through `GrantRole`.
 */
final class CreateUserFromGoogle
{
    use ProfileValidationRules;

    public function __construct(private readonly GrantRole $grantRole) {}

    /**
     * @param  array<string, mixed>  $input  the completion form: `name`, `terms`, and `agreement` when invited
     *
     * @throws GoogleSignInRefused when the address or the Google account was taken meanwhile
     */
    public function create(GoogleProfile $profile, array $input, bool $invited = false): User
    {
        $data = Validator::make([
            'name' => $input['name'] ?? null,
            'terms' => $input['terms'] ?? null,
            'email' => $profile->email,
            'google_id' => $profile->id,
            ...($invited ? ['agreement' => $input['agreement'] ?? null] : []),
        ], [
            'name' => $this->nameRules(),
            'terms' => ['accepted'],
            'email' => $this->emailRules(),
            'google_id' => ['required', 'string', 'max:255', Rule::unique(User::class, 'google_id')],
            ...($invited ? ['agreement' => RoleChoiceData::rules()['agreement']] : []),
        ], [
            'terms.accepted' => __('auth.register.terms_required'),
            'agreement' => RoleChoiceData::messages()['agreement'],
        ])->validate();

        $user = new User([
            'name' => $data['name'],
            'email' => $profile->email,
            // The language the teacher registered in stays theirs.
            'locale' => app()->getLocale(),
        ]);

        // Not mass assignable: only sign-up sets them.
        $user->forceFill([
            'google_id' => $profile->id,
            'email_verified_at' => now(),
            'terms_accepted_at' => now(),
        ]);

        try {
            DB::transaction(function () use ($user, $invited): void {
                $user->save();

                if ($invited) {
                    $this->grantRole->handle($user, new RoleChoiceData(Role::Player));
                }
            });
        } catch (UniqueConstraintViolationException) {
            // Taken between the check and the insert (a second tab, a second
            // form). Reported as a refusal: the exception's SQL holds the
            // teacher's name and address, which never go into a log.
            throw new GoogleSignInRefused('auth.google.failed');
        }

        return $user;
    }
}
