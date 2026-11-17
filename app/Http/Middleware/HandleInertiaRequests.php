<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\User;
use App\Support\Translations;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => $locale,
            'locales' => Translations::locales(),
            'translations' => fn (): array => Translations::for($locale),
            // An account, never a guest board's bearer (docs/architecture.md §4),
            // with its roles and the area whose navigation the page shows (§1
            // "Accounts and roles").
            'auth' => [
                'user' => $user,
                'roles' => $user === null ? [] : array_map(static fn (Role $role): string => $role->value, $user->roleList()),
                'area' => $user === null ? null : $this->area($request, $user)?->value,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The area of the page: the route's own inside the teacher, the parent
     * or the player group, otherwise the account's (ADR 010 decision 7),
     * which the shared and account pages follow. A shared page is a
     * board's, so it is never in the player area: an account there sees
     * the first of its teacher and parent areas (ADR 019 decisions 5, 8).
     * A role this instance does not offer names no area (decision 4).
     */
    private function area(Request $request, User $user): ?Role
    {
        $middleware = $request->route()?->gatherMiddleware() ?? [];

        foreach (Role::available() as $role) {
            if (in_array(Role::middleware($role), $middleware, true)) {
                return $role;
            }
        }

        $area = $user->area();

        if ($area === Role::Player && in_array(Role::middleware(Role::Teacher, Role::Parent), $middleware, true)) {
            return array_values(array_filter($user->roleList(), static fn (Role $role): bool => $role !== Role::Player))[0] ?? $area;
        }

        return $area;
    }
}
