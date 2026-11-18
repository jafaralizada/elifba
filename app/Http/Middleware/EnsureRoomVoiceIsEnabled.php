<?php

namespace App\Http\Middleware;

use App\Support\RoomsArea;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `rooms.voice` (docs/architecture.md §12 "Live voice", ADR 031 decision
 * 28): while the instance's live voice is off — or the area is, or the
 * voice server is not fully named — every voice route answers 404 to
 * everyone, right after `rooms.enabled` and before the cookies, the
 * session or the login could say that it exists. The routes stay
 * registered, so Wayfinder generates the same files everywhere.
 */
final class EnsureRoomVoiceIsEnabled
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(RoomsArea::voice(), Response::HTTP_NOT_FOUND);

        return $next($request);
    }
}
