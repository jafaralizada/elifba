<?php

namespace App\Domain\Rooms\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RoomAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player's answer to a room round (docs/architecture.md §1
 * `room_answers`, §12 "The race", "Scoring and what is kept", ADRs 021–022):
 * one per player per round, final, written only by `RecordRoomAnswer` and
 * stored once per `event_id`, which the device makes. It holds the round's
 * own item ids in the order tapped — never text — and what the server made
 * of them: right or wrong (`RoomJudge`), the time from the question to the
 * server's receipt and the points (`RoomPointsRule`).
 *
 * @property int $id
 * @property string $event_id ULID made by the device
 * @property string $room_round_id
 * @property string $room_player_id
 * @property list<string> $picked item ids of the round, in the order tapped
 * @property bool $correct
 * @property int $elapsed_ms the server's receipt − the moment the question opened
 * @property int $points 0, or 10–20
 * @property CarbonImmutable $created_at
 * @property-read RoomRound $round
 * @property-read RoomPlayer $player
 */
#[UseFactory(RoomAnswerFactory::class)]
class RoomAnswer extends Model
{
    /** @use HasFactory<RoomAnswerFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<RoomRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(RoomRound::class, 'room_round_id');
    }

    /**
     * @return BelongsTo<RoomPlayer, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'picked' => 'array',
            'correct' => 'boolean',
            'elapsed_ms' => 'integer',
            'points' => 'integer',
        ];
    }
}
