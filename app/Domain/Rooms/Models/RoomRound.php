<?php

namespace App\Domain\Rooms\Models;

use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomAskData;
use App\Domain\Rooms\Data\RoomSolutionData;
use App\Domain\Rooms\Data\RoomStudyData;
use Database\Factories\RoomRoundFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A round of a room game (docs/architecture.md §1 `room_rounds`, §12 "The
 * race", ADR 021 decision 3), built when the game starts and stored as two
 * snapshots: `payload`, what players may see — the study material and the
 * question, each served only in its phase — and `solution`, what only
 * `RoomJudge` reads. The solution is hidden from every serialization of
 * the model (`toArray`, `toJson`, a relation of a game); it reaches a
 * device only through the reveal, which part of the state shows it.
 *
 * `closed_ms` is the one fact a round gets after it starts: every present
 * player answered before the bell. Moments are Unix milliseconds.
 *
 * @phpstan-import-type RoomPayloadArray from BuiltRoomRoundData
 * @phpstan-import-type RoomSolutionArray from RoomSolutionData
 *
 * @property string $id
 * @property string $room_game_id
 * @property int $position 0-based
 * @property RoomPayloadArray $payload
 * @property RoomSolutionArray $solution
 * @property int $study_ms 0 except in memory games
 * @property int $answer_ms
 * @property int|null $closed_ms every present player had answered before the bell
 * @property-read RoomGame $game
 * @property-read Collection<int, RoomAnswer> $answers
 */
#[UseFactory(RoomRoundFactory::class)]
class RoomRound extends Model
{
    /** @use HasFactory<RoomRoundFactory> */
    use HasFactory, HasUlids;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $hidden = ['solution'];

    /**
     * The material to study, or null for a round without it.
     */
    public function studyData(): ?RoomStudyData
    {
        $study = $this->payload['study'];

        return $study === null ? null : RoomStudyData::fromArray($study);
    }

    public function askData(): RoomAskData
    {
        return RoomAskData::fromArray($this->payload['ask']);
    }

    public function solutionData(): RoomSolutionData
    {
        return RoomSolutionData::fromArray($this->solution);
    }

    /**
     * @return BelongsTo<RoomGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(RoomGame::class, 'room_game_id');
    }

    /**
     * @return HasMany<RoomAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(RoomAnswer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'payload' => 'array',
            'solution' => 'array',
            'study_ms' => 'integer',
            'answer_ms' => 'integer',
            'closed_ms' => 'integer',
        ];
    }
}
