<?php

namespace App\Domain\Rooms\Data;

/**
 * A taunt as a device sees it (docs/architecture.md §12 "Taunts (T2)", ADR
 * 029 decision 15): its id, the thrower's row (`from`), its key and the
 * server's moment of the throw — and, on the big screen only, the target's
 * row (`to`). A page reads both names from the state's `players` and the
 * phrase from its own translations; nothing else of a taunt leaves the
 * server, and it is in a state only for `elifba.rooms.taunts.show_ms`
 * after the throw.
 *
 * @phpstan-type RoomTauntSeenArray array{id: string, from: string, to?: string, taunt: string, sent_ms: int}
 */
final readonly class RoomTauntSeenData
{
    /**
     * @param  string|null  $to  the target's row: the screen's `shown`; null in the target's own `received`
     */
    public function __construct(
        public string $id,
        public string $from,
        public ?string $to,
        public string $taunt,
        public int $sent_ms,
    ) {}

    /**
     * @param  RoomTauntSeenArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['from'], $data['to'] ?? null, $data['taunt'], $data['sent_ms']);
    }

    /**
     * @return RoomTauntSeenArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'from' => $this->from,
            ...($this->to === null ? [] : ['to' => $this->to]),
            'taunt' => $this->taunt,
            'sent_ms' => $this->sent_ms,
        ];
    }
}
