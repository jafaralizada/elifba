<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentAssets;

/**
 * The rivalry strip's three emoji, from `content/rooms/rivalry.json`
 * (docs/architecture.md §12 "Room content", "Rivals face to face (P2)",
 * ADR 027 decision 10): Fluent Emoji (Color) files under
 * `content/assets/fluent/` — ⚡ `clash` where the two sides meet, 👑
 * `leader` by the first place, 🔥 `overtake` on a change of lead. They
 * show the state and never stand for a person. A room page gets their
 * URLs as the prop `rivalry`; each URL carries the file's `version`, as
 * every content URL does.
 */
final readonly class RoomRivalryData
{
    /** The icons, in the order of the file. */
    public const array ICONS = ['clash', 'leader', 'overtake'];

    /**
     * @param  array{clash: string, leader: string, overtake: string}  $icons  relative to `content/assets` (`fluent/26A1.svg`)
     */
    public function __construct(
        public int $version,
        public array $icons,
    ) {}

    /**
     * @param  array{version: int, icons: array{clash: string, leader: string, overtake: string}}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['version'], $data['icons']);
    }

    /**
     * The icons' URLs by name: the prop `rivalry` of `rooms/show` and
     * `rooms/screen`.
     *
     * @return array{clash: string, leader: string, overtake: string}
     */
    public function urls(): array
    {
        return [
            'clash' => ContentAssets::url($this->icons['clash'], $this->version),
            'leader' => ContentAssets::url($this->icons['leader'], $this->version),
            'overtake' => ContentAssets::url($this->icons['overtake'], $this->version),
        ];
    }
}
