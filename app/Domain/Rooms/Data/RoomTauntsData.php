<?php

namespace App\Domain\Rooms\Data;

use InvalidArgumentException;

/**
 * The taunts of a live game as one viewer's state carries them
 * (docs/architecture.md §12 "Taunts (T2)", ADR 029 decision 15; zod
 * `RoomTaunts`):
 *
 * - `open`: a throw would be accepted now but for the balance and the
 *   cooldown — the viewer plays, the host's switch is on, and the game's
 *   last round has not closed;
 * - `balance`: what the viewer may spend (`RoomTauntBalance`); null for a
 *   viewer who does not play;
 * - `ready_ms`: the end of the viewer's cooldown while it runs;
 * - `received`: the taunts aimed at the viewer within `show_ms` of the
 *   state's `server_ms`, newest first, at most `MAX_LISTED`; `[]` while the
 *   switch is off; never the viewer's own throws;
 * - `shown`: the same window's taunts of the game, with their targets, for
 *   the big screen's request only (`?for=screen`); null otherwise.
 *
 * @phpstan-import-type RoomTauntSeenArray from RoomTauntSeenData
 *
 * @phpstan-type RoomTauntsArray array{open: bool, balance: int|null, ready_ms: int|null, received: list<RoomTauntSeenArray>, shown: list<RoomTauntSeenArray>|null}
 */
final readonly class RoomTauntsData
{
    /** The most taunts a list holds; a page shows two and a third waits. */
    public const int MAX_LISTED = 3;

    /**
     * @param  list<RoomTauntSeenData>  $received
     * @param  list<RoomTauntSeenData>|null  $shown
     */
    public function __construct(
        public bool $open,
        public ?int $balance,
        public ?int $ready_ms,
        public array $received,
        public ?array $shown,
    ) {
        if ($balance !== null && $balance < 0) {
            throw new InvalidArgumentException('A balance is never below 0.');
        }

        foreach ($received as $taunt) {
            if ($taunt->to !== null) {
                throw new InvalidArgumentException('A taunt the viewer received does not name its target.');
            }
        }

        foreach ($shown ?? [] as $taunt) {
            if ($taunt->to === null) {
                throw new InvalidArgumentException('A taunt the screen shows names its target.');
            }
        }
    }

    /**
     * @param  RoomTauntsArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            open: $data['open'],
            balance: $data['balance'],
            ready_ms: $data['ready_ms'],
            received: array_map(RoomTauntSeenData::fromArray(...), $data['received']),
            shown: $data['shown'] === null ? null : array_map(RoomTauntSeenData::fromArray(...), $data['shown']),
        );
    }

    /**
     * @return RoomTauntsArray
     */
    public function toArray(): array
    {
        return [
            'open' => $this->open,
            'balance' => $this->balance,
            'ready_ms' => $this->ready_ms,
            'received' => array_map(static fn (RoomTauntSeenData $taunt): array => $taunt->toArray(), $this->received),
            'shown' => $this->shown === null ? null : array_map(static fn (RoomTauntSeenData $taunt): array => $taunt->toArray(), $this->shown),
        ];
    }
}
