<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomGamePhase;
use App\Domain\Rooms\Enums\RoomStatus;
use InvalidArgumentException;

/**
 * A room's state (docs/architecture.md §12 "The state"; zod `RoomState` in
 * resources/js/rooms/contract.ts, fixtures tests/Fixtures/rooms/state.*.json):
 * the one thing a room page reads after its first props, from
 * `GET /rooms/{room}/state`, and the `state` of those props. Names reach a
 * page only here and in the page's props, in authenticated HTTP; a player
 * is known by `room_players.id`.
 *
 * Three shapes, which the constructor holds to: no game yet (`game` and
 * `round` null, the room's ranking); a live game with its current round
 * (the game's ranking over its closed rounds); a game that is over (no
 * round, the room's standing). What a round serves in each phase is
 * `RoomRoundData`'s rule. An open room also has `setup`, the host's
 * choice of the next game (G2, ADR 026 decision 11; version 2); a closed
 * one has none. A live game also has its taunts (`taunts`, T2, ADR 029
 * decision 15; version 3) as the viewer may see them; nothing else has.
 *
 * @phpstan-import-type RoomSummaryArray from RoomSummaryData
 * @phpstan-import-type RoomViewerArray from RoomViewerData
 * @phpstan-import-type RoomPlayerArray from RoomPlayerData
 * @phpstan-import-type RoomGameArray from RoomGameData
 * @phpstan-import-type RoomRoundArray from RoomRoundData
 * @phpstan-import-type RoomRankedPlayerArray from RoomRankedPlayerData
 * @phpstan-import-type RoomSetupArray from RoomSetupData
 * @phpstan-import-type RoomTauntsArray from RoomTauntsData
 *
 * @phpstan-type RoomStateArray array{v: int, revision: int, server_ms: int, room: RoomSummaryArray, me: RoomViewerArray, players: list<RoomPlayerArray>, game: RoomGameArray|null, round: RoomRoundArray|null, ranking: list<RoomRankedPlayerArray>, setup: RoomSetupArray|null, taunts: RoomTauntsArray|null}
 */
final readonly class RoomStateData
{
    public const int VERSION = 3;

    /**
     * @param  list<RoomPlayerData>  $players
     * @param  list<RoomRankedPlayerData>  $ranking
     */
    public function __construct(
        public int $revision,
        public int $server_ms,
        public RoomSummaryData $room,
        public RoomViewerData $me,
        public array $players,
        public ?RoomGameData $game = null,
        public ?RoomRoundData $round = null,
        public array $ranking = [],
        public ?RoomSetupData $setup = null,
        public ?RoomTauntsData $taunts = null,
    ) {
        $live = $game?->phase === RoomGamePhase::Live;

        if (($room->status === RoomStatus::Open) !== ($setup !== null)) {
            throw new InvalidArgumentException('An open room has its choice of the next game, and a closed one has none.');
        }

        if ($live !== ($round !== null)) {
            throw new InvalidArgumentException('A live game has its current round, and nothing else has one.');
        }

        if ($live !== ($taunts !== null)) {
            throw new InvalidArgumentException('A live game has its taunts, and nothing else has any.');
        }

        if ($round !== null && $round->total !== $game?->rounds) {
            throw new InvalidArgumentException('The round counts another game\'s rounds.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['v'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('A room state of another version.');
        }

        /** @var RoomStateArray $data */
        return new self(
            revision: $data['revision'],
            server_ms: $data['server_ms'],
            room: RoomSummaryData::fromArray($data['room']),
            me: RoomViewerData::fromArray($data['me']),
            players: array_map(RoomPlayerData::fromArray(...), $data['players']),
            game: $data['game'] === null ? null : RoomGameData::fromArray($data['game']),
            round: $data['round'] === null ? null : RoomRoundData::fromArray($data['round']),
            ranking: array_map(RoomRankedPlayerData::fromArray(...), $data['ranking']),
            setup: $data['setup'] === null ? null : RoomSetupData::fromArray($data['setup']),
            taunts: $data['taunts'] === null ? null : RoomTauntsData::fromArray($data['taunts']),
        );
    }

    /**
     * @return RoomStateArray
     */
    public function toArray(): array
    {
        return [
            'v' => self::VERSION,
            'revision' => $this->revision,
            'server_ms' => $this->server_ms,
            'room' => $this->room->toArray(),
            'me' => $this->me->toArray(),
            'players' => array_map(static fn (RoomPlayerData $player): array => $player->toArray(), $this->players),
            'game' => $this->game?->toArray(),
            'round' => $this->round?->toArray(),
            'ranking' => array_map(static fn (RoomRankedPlayerData $row): array => $row->toArray(), $this->ranking),
            'setup' => $this->setup?->toArray(),
            'taunts' => $this->taunts?->toArray(),
        ];
    }
}
