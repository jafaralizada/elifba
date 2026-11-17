<?php

namespace App\Domain\Rooms\Policies;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Models\User;

/**
 * Who reaches what in a room, second layer (docs/architecture.md §12 "Rooms
 * and joining", ADR 020 decision 5): `{room}` already binds only a room the
 * account hosts or has a row in that was not removed, and every route with
 * it also passes one of these (`->can()`). Three abilities are §12's —
 * `view`, `play`, `host` — and the others join one of them to the room's
 * state, one for each condition of §12's route table. None asks for a role.
 */
final class RoomPolicy
{
    /** The room page: the host, or a player, present or past. */
    public function view(User $user, Room $room): bool
    {
        return $room->isHostedBy($user) || $room->rowOf($user) !== null;
    }

    /** A present player of an open room: the live room, leaving. */
    public function play(User $user, Room $room): bool
    {
        return $room->isOpen() && ($room->rowOf($user)?->isPresent() ?? false);
    }

    /** The host, playing or not: the host's tools. */
    public function host(User $user, Room $room): bool
    {
        return $room->isHostedBy($user);
    }

    /** The state: `play` or `host`. */
    public function watch(User $user, Room $room): bool
    {
        return $this->host($user, $room) || $this->play($user, $room);
    }

    /** `host`, and the row is a player's of this room, never the host's own. */
    public function remove(User $user, Room $room, RoomPlayer $member): bool
    {
        return $this->host($user, $room)
            && $member->room_id === $room->id
            && $member->user_id !== $room->user_id;
    }

    /** `host`, once the room is closed. */
    public function delete(User $user, Room $room): bool
    {
        return $this->host($user, $room) && ! $room->isOpen();
    }

    /** `view` with a row of one's own, once the room is closed: taking one's name out. */
    public function forget(User $user, Room $room): bool
    {
        return ! $room->isOpen() && $room->rowOf($user) !== null;
    }
}
