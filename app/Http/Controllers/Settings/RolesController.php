<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Roles\GrantRole;
use App\Data\RoleChoiceData;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\RoomsArea;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → «Rollar» (docs/architecture.md §1 "Accounts and roles", ADR 010
 * decision 6): the roles the account holds and the ones this instance
 * offers that it lacks, each taken with that role's sentence. A role is
 * never removed. A page of the player area sends an account without the
 * role here, «Oyunçu» preselected (ADR 019 decision 6).
 */
final class RolesController extends Controller
{
    public function edit(Request $request, #[CurrentUser] User $user): Response
    {
        $held = $user->roleList();
        $available = array_values(array_filter(Role::available(), static fn (Role $role): bool => ! in_array($role, $held, true)));

        return Inertia::render('settings/roles', [
            'held' => array_map(static fn (Role $role): string => $role->value, $held),
            'available' => array_map(static fn (Role $role): string => $role->value, $available),
            'chosen' => RoleChoiceData::preselected($request, $available)?->value,
        ]);
    }

    /**
     * Takes the role and opens its area, where `GrantRole` moved the account
     * — or, for the player role taken on the way into a room page, that page;
     * a role it already holds leaves everything as it was.
     */
    public function store(Request $request, #[CurrentUser] User $user, GrantRole $grantRole): RedirectResponse
    {
        $data = RoleChoiceData::validate($request->all());

        if (! $grantRole->handle($user, $data)) {
            return to_route('roles.edit');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('roles.settings.added')]);

        $intended = $data->role === Role::Player ? RoomsArea::pullIntended($request) : null;

        return $intended !== null ? redirect()->to($intended) : to_route($data->role->homeRoute());
    }
}
