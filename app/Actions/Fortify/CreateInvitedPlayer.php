<?php

namespace App\Actions\Fortify;

use App\Actions\Roles\GrantRole;
use App\Concerns\ProfileValidationRules;
use App\Data\RoleChoiceData;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * The account of a visitor who opened a room's link by hand, made at the
 * invitation's door (docs/architecture.md §12 "The invitation link (I2)",
 * §7 "Agreements", ADR 033 decision 12): the name and the address as
 * registration takes them, one password (the address is confirmed before
 * the first game, so a mistyped one is mended by «Şifrəni unutmusunuz?»),
 * and two agreements, never merged — the privacy note's (`terms`, as
 * registration) and the player's sentence (`agreement`). In one
 * transaction the account is created as `CreateNewUser` creates it and
 * takes the player role through `GrantRole`, the only place a role is
 * given, which stamps the sentence and sets the area.
 */
final readonly class CreateInvitedPlayer
{
    use ProfileValidationRules;

    public function __construct(private GrantRole $grantRole) {}

    /**
     * @param  array<string, mixed>  $input  the door's form: `name`, `email`, `password`, `terms`, `agreement`
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => ['required', 'string', Password::default()],
            'terms' => ['accepted'],
            'agreement' => RoleChoiceData::rules()['agreement'],
        ], [
            'terms.accepted' => __('auth.register.terms_required'),
            'agreement' => RoleChoiceData::messages()['agreement'],
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = new User([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
                // The language the player registered in stays theirs.
                'locale' => app()->getLocale(),
            ]);

            // Not mass assignable: only registration sets it.
            $user->forceFill(['terms_accepted_at' => now()])->save();

            $this->grantRole->handle($user, new RoleChoiceData(Role::Player));

            return $user;
        });
    }
}
