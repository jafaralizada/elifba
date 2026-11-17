<?php

namespace App\Domain\Rooms\Data;

use InvalidArgumentException;

/**
 * A room's state (docs/architecture.md §12 "The state"; zod `RoomState` in
 * resources/js/rooms/contract.ts, fixture tests/Fixtures/rooms/state.*.json):
 * the one thing a room page reads after its first props, from
 * `GET /rooms/{room}/state`. Names reach a page only here and in the page's
 * props, in authenticated HTTP; a player is known by `room_players.id`.
 *
 * B2 serves a room without a game: `game` and `round` are null and
 * `ranking` is empty. B3 gives them their DTOs; until then a state that
 * carries one does not parse.
 *
 * @phpstan-import-type RoomSummaryArray from RoomSummaryData
 * @phpstan-import-type RoomViewerArray from RoomViewerData
 * @phpstan-import-type RoomPlayerArray from RoomPlayerData
 *
 * @phpstan-type RoomStateArray array{v: int, revision: int, server_ms: int, room: RoomSummaryArray, me: RoomViewerArray, players: list<RoomPlayerArray>, game: null, round: null, ranking: array{}}
 */
final readonly class RoomStateData
{
    public const int VERSION = 1;

    /**
     * @param  list<RoomPlayerData>  $players
     */
    public function __construct(
        public int $revision,
        public int $server_ms,
        public RoomSummaryData $room,
        public RoomViewerData $me,
        public array $players,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        if (($data['v'] ?? null) !== self::VERSION) {
            throw new InvalidArgumentException('A room state of another version.');
        }

        if (($data['game'] ?? null) !== null || ($data['round'] ?? null) !== null || ($data['ranking'] ?? []) !== []) {
            throw new InvalidArgumentException('A room state with a game is B3\'s.');
        }

        /** @var RoomStateArray $data */
        return new self(
            revision: $data['revision'],
            server_ms: $data['server_ms'],
            room: RoomSummaryData::fromArray($data['room']),
            me: RoomViewerData::fromArray($data['me']),
            players: array_map(RoomPlayerData::fromArray(...), $data['players']),
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
            'game' => null,
            'round' => null,
            'ranking' => [],
        ];
    }
}
