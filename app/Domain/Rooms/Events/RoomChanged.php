<?php

namespace App\Domain\Rooms\Events;

use App\Domain\Rooms\Support\RoomChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * `room.changed` on the room's channel (docs/architecture.md §12 "The
 * race", ADR 021 decision 8): something the room sees changed — a join, a
 * leave, a removal, the lock, the close — so a page with an older revision
 * fetches the state. Sent at once (no queue) by `RoomSignal` after the
 * change is committed. The payload is `{v: 1, revision}` and nothing else:
 * the channel names the room; no name, content, answer or result.
 */
final readonly class RoomChanged implements ShouldBroadcastNow
{
    public function __construct(
        public string $roomId,
        public int $revision,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(RoomChannel::name($this->roomId));
    }

    public function broadcastAs(): string
    {
        return 'room.changed';
    }

    /**
     * @return array{v: int, revision: int}
     */
    public function broadcastWith(): array
    {
        return ['v' => 1, 'revision' => $this->revision];
    }
}
