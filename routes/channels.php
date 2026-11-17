<?php

use App\Domain\Play\Models\GuestBoard;
use App\Domain\Play\Support\GameChannel;
use App\Domain\Rooms\Support\RoomChannel;
use App\Models\User;
use App\Support\RoomsArea;
use Illuminate\Support\Facades\Broadcast;

/*
 * One private channel per game, `private-game.{id}` (docs/architecture.md
 * §4): the owner's session, or the bearer of the guest board that shows
 * the game, while it is live or paused. The bearer is tried first, as on
 * the board routes. Reverb takes client events only from members
 * (`accept_client_events_from = members`). Payloads carry ids only.
 */
Broadcast::channel(
    'game.{game}',
    static fn (User|GuestBoard $who, string $game): bool => GameChannel::allows($who, $game),
    ['guards' => ['board', 'web']],
);

/*
 * One private channel per room, `private-room.{id}` (docs/architecture.md
 * §12 "The race", ADR 021 decision 8): an account's session only — never a
 * guest board's bearer — while the rooms area is switched on, for the host
 * or a present player of an open room. The server sends `room.changed`
 * with the revision and nothing else; no client event means anything here.
 */
Broadcast::channel(
    'room.{room}',
    static fn (User $user, string $room): bool => RoomsArea::enabled() && RoomChannel::allows($user, $room),
    ['guards' => ['web']],
);
