<?php

namespace App\Support;

use App\Enums\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The adults' rooms area (docs/architecture.md §12 "The area and the
 * switch", ADR 019): whether it is switched on — the one reader of
 * `elifba.rooms.enabled` — which routes are in it, and the way in of an
 * account that opens a room page without the player role: to where roles
 * are taken, «Oyunçu» preselected, and back to that page once the role is
 * taken (decision 6).
 */
final class RoomsArea
{
    /** Off unless the instance sets `ELIFBA_ROOMS`. */
    public static function enabled(): bool
    {
        return Config::boolean('elifba.rooms.enabled', false);
    }

    /** Whether a route is in the player area: the only group that names the player role. */
    public static function holds(Route $route): bool
    {
        return in_array(Role::middleware(Role::Player), $route->gatherMiddleware(), true);
    }

    /**
     * Sends a page visit of the player area to where roles are taken — the
     * route `$rolesPage`, with «Oyunçu» preselected — and keeps the page as
     * the intended address. Null for any other request, which keeps its own
     * way (ADR 010: a wrong area leads to `/start`).
     */
    public static function toThePlayerRole(Request $request, string $rolesPage): ?RedirectResponse
    {
        $route = $request->route();

        if (! $route instanceof Route || ! self::enabled() || ! self::holds($route)) {
            return null;
        }

        redirect()->setIntendedUrl($request->fullUrl());

        return to_route($rolesPage, ['role' => Role::Player->value]);
    }

    /**
     * The intended address, taken out of the session, when it is a page of
     * the player area on this app's own address; otherwise null, and the
     * session keeps what it had. Never an address elsewhere.
     */
    public static function pullIntended(Request $request): ?string
    {
        $url = $request->session()->get('url.intended');

        if (! is_string($url) || ! self::isAddress($request, $url)) {
            return null;
        }

        $request->session()->forget('url.intended');

        return $url;
    }

    /** Whether `$url` opens a page of the player area of this app. */
    private static function isAddress(Request $request, string $url): bool
    {
        if (! str_starts_with($url, $request->root().'/')) {
            return false;
        }

        try {
            $route = Router::getRoutes()->match(Request::create($url));
        } catch (HttpException) {
            return false;
        }

        return self::holds($route);
    }
}
