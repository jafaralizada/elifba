<?php

namespace App\Http\Controllers;

use App\Actions\Roles\GrantRole;
use App\Data\RoleChoiceData;
use App\Enums\Role;
use App\Models\User;
use App\Support\RoomsArea;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The role choice right after registration (docs/architecture.md §1
 * "Accounts and roles", ADR 010 decision 4): the roles this instance offers
 * (`Role::available()`; «Oyunçu» only while the rooms area is on, ADR 019),
 * each with its sentence. `auth` only, so it comes before e-mail
 * confirmation. An account that already has a role is sent on to `/start`;
 * another role is added in Settings → «Rollar».
 */
final class RoleController extends Controller
{
    public function create(Request $request, #[CurrentUser] User $user): Response|RedirectResponse
    {
        if ($user->roleList() !== []) {
            return to_route('start');
        }

        $roles = Role::available();

        return Inertia::render('auth/choose-role', [
            'roles' => array_map(static fn (Role $role): string => $role->value, $roles),
            'chosen' => RoleChoiceData::preselected($request, $roles)?->value,
        ]);
    }

    /**
     * Takes the role and goes to `/start`; the player role taken on the way
     * into a room page goes back to that page (ADR 019 decision 6).
     */
    public function store(Request $request, #[CurrentUser] User $user, GrantRole $grantRole): RedirectResponse
    {
        if ($user->roleList() !== []) {
            return to_route('start');
        }

        $data = RoleChoiceData::validate($request->all());
        $grantRole->handle($user, $data);

        $intended = $data->role === Role::Player ? RoomsArea::pullIntended($request) : null;

        return $intended !== null ? redirect()->to($intended) : to_route('start');
    }
}
