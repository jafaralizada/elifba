<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;

/**
 * A difficulty of a room mechanic, from `content/rooms/levels.json`
 * (docs/architecture.md §12 "Room games", "Room content", ADR 023): one
 * per mechanic and difficulty 1–4 («Asan», «Orta», «Çətin», «Çox çətin»),
 * so the pair is the level's key. It holds the times every mechanic has
 * and the numbers of its own mechanic; the fields of another mechanic are
 * empty. `ContentValidator` checks them all.
 *
 * - `recall_order`: `kinds` (one per round) and `length` (items in a row).
 *
 * @phpstan-type RoomLevelArray array{mechanic: string, difficulty: int, study_ms: int, answer_ms: int, kinds?: list<string>, length?: array{min: int, max: int}}
 */
final readonly class RoomLevelData
{
    /**
     * @param  int  $difficulty  1–4
     * @param  int  $studyMs  how long the material is shown; 0 unless the mechanic `studies()`
     * @param  int  $answerMs  the time to answer
     * @param  list<RoomItemKind>  $kinds  what a `recall_order` round may show
     * @param  RangeData|null  $length  items in a `recall_order` row
     */
    public function __construct(
        public RoomMechanic $mechanic,
        public int $difficulty,
        public int $studyMs,
        public int $answerMs,
        public array $kinds = [],
        public ?RangeData $length = null,
    ) {}

    /**
     * @param  RoomLevelArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mechanic: RoomMechanic::from($data['mechanic']),
            difficulty: $data['difficulty'],
            studyMs: $data['study_ms'],
            answerMs: $data['answer_ms'],
            kinds: array_map(RoomItemKind::from(...), $data['kinds'] ?? []),
            length: isset($data['length']) ? RangeData::fromArray($data['length']) : null,
        );
    }
}
