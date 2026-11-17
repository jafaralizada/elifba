<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomPlayable;
use App\Domain\Rooms\Support\RoomSeed;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * The host starts a game (docs/architecture.md §12 "The race", ADR 021):
 * under the room row's lock it refuses a closed room and a room whose game
 * is still running — `RoomPolicy::start` refused them already, and two
 * starts at once meet here — then builds every round with the mechanic's
 * builder from a fresh seed (`RoomSeed`), stores each as its `payload` and
 * its `solution`, and sets round 0 to open `elifba.rooms.countdown_ms`
 * from now, with the configured `reveal_ms` kept as the game's. The room
 * is active, its revision rises and it is signalled.
 */
final readonly class StartRoomGame
{
    public function __construct(private ContentRepository $content) {}

    /**
     * @throws AuthorizationException when the room is closed or a game is running in it
     */
    public function handle(Room $room, RoomGameSetupData $setup): RoomGame
    {
        return DB::transaction(function () use ($room, $setup): RoomGame {
            $locked = RoomLock::room($room);
            $nowMs = RoomClock::nowMs();

            if ($locked === null || ! $locked->isOpen() || $locked->runningGame($nowMs) !== null) {
                throw new AuthorizationException;
            }

            $seed = RoomSeed::fresh();
            $built = RoomPlayable::builder($setup->mechanic)->build(
                $this->content->roomLevel($setup->mechanic, $setup->difficulty),
                $setup->rounds,
                RoomSeed::random($seed),
            );

            $game = new RoomGame;
            $game->room_id = $locked->id;
            $game->position = $locked->games()->count();
            $game->mechanic = $setup->mechanic;
            $game->difficulty = $setup->difficulty;
            $game->content_version = $this->content->roomVersion();
            $game->seed = $seed;
            $game->reveal_ms = Config::integer('elifba.rooms.reveal_ms');
            $game->started_ms = $nowMs + Config::integer('elifba.rooms.countdown_ms');
            $game->save();

            foreach ($built as $position => $round) {
                $row = new RoomRound;
                $row->room_game_id = $game->id;
                $row->position = $position;
                $row->payload = $round->payload();
                $row->solution = $round->solution->toArray();
                $row->study_ms = $round->studyMs;
                $row->answer_ms = $round->answerMs;
                $row->save();
            }

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            return $game;
        });
    }
}
