<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\Room;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The private channel of a room, `private-room.{id}` (docs/architecture.md
 * §12 "The race", ADR 021 decision 8), and who may join it: an account
 * that holds the player role (while the area is switched on, `roleList()`
 * names it) and is the host or a present player of the room, while the
 * room is open. Only an account's session reaches it (`routes/channels.php`
 * names the `web` guard alone, so a guest board's bearer never does). The
 * server sends one signal on it, `room.changed` with the revision; no name,
 * content or result ever travels there, and no client event means
 * anything.
 */
final class RoomChannel
{
    public static function name(string $roomId): string
    {
        return 'room.'.$roomId;
    }

    public static function allows(User $user, string $roomId): bool
    {
        if (! Str::isUlid($roomId) || ! in_array(Role::Player, $user->roleList(), true)) {
            return false;
        }

        $room = Room::query()->find($roomId);

        return $room !== null
            && $room->isOpen()
            && ($room->isHostedBy($user) || ($room->rowOf($user)?->isPresent() ?? false));
    }
}
