<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomState;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/**
 * `GET /rooms/{room}/state` (zod `RoomState`, docs/architecture.md §12 "The
 * state"): for the host and a present player of an open room, never kept
 * by a cache. 120 a minute per account (`throttle:room-state`).
 */
final class RoomStateController extends Controller
{
    public function __invoke(Room $room, #[CurrentUser] User $account): JsonResponse
    {
        return new JsonResponse(RoomState::for($room, $account)->toArray(), 200, ['Cache-Control' => 'no-store, private']);
    }
}
