<?php

namespace App\Http\Middleware;

use App\Support\RoomsArea;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `rooms.enabled` (docs/architecture.md §12 "The area and the switch",
 * ADR 019 decision 4): while the instance has the rooms area switched off,
 * every room route answers 404 to everyone — before the login, the role or
 * anything else could say that the route exists. The routes stay
 * registered, so Wayfinder generates the same files everywhere.
 */
final class EnsureRoomsAreEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(RoomsArea::enabled(), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
