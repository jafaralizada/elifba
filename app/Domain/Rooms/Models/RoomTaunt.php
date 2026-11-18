<?php

namespace App\Domain\Rooms\Models;

use Database\Factories\RoomTauntFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ready-made taunt one player threw at another during a room game
 * (docs/architecture.md §1 `room_taunts`, §12 "Taunts (T2)", ADR 029):
 * written only by `ThrowRoomTaunt`, stored once per `event_id`, which the
 * device makes. It holds a key of `content/rooms/taunts.json` — never text
 * — the thrower's and the target's rows and the server's moment
 * (`RoomClock`), from which `RoomRanking` reads the close at which the
 * thrower's cost (`RoomPointsRule::TAUNT_COST`) counts.
 *
 * The target is emptied a day after the throw (`PruneOldRooms`): only the
 * target's phone ever needed it. A device gets a taunt only through the
 * state, for `elifba.rooms.taunts.show_ms`, by ids and its key.
 *
 * @property string $id
 * @property string $event_id ULID made by the device
 * @property string $room_game_id
 * @property string $room_player_id the thrower, who paid
 * @property string|null $target_player_id null once forgotten, or the row is gone
 * @property string $taunt a key of `content/rooms/taunts.json`
 * @property int $sent_ms Unix ms, the server's moment of the throw
 * @property-read RoomGame $game
 * @property-read RoomPlayer $player
 * @property-read RoomPlayer|null $target
 */
#[UseFactory(RoomTauntFactory::class)]
class RoomTaunt extends Model
{
    /** @use HasFactory<RoomTauntFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    /**
     * @return BelongsTo<RoomGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(RoomGame::class, 'room_game_id');
    }

    /**
     * @return BelongsTo<RoomPlayer, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }

    /**
     * @return BelongsTo<RoomPlayer, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'target_player_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_ms' => 'integer',
        ];
    }
}
