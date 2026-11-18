<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\RoomSound;
use App\Domain\Content\Enums\RoomSpeaker;
use App\Domain\Content\Support\ContentAssets;

/**
 * The sounds of a room game, from `content/rooms/sounds.json`
 * (docs/architecture.md §12 "Room content", ADR 025 "Sound"): one MP3 of
 * our own under `content/assets/rooms/sounds/` for every `RoomSound`. A
 * page gets the URLs of its own speaker's sounds in its props; each URL
 * carries the file's `version`, as every content URL does.
 */
final readonly class RoomSoundsData
{
    /**
     * @param  array<value-of<RoomSound>, string>  $files  relative to `content/assets` (`rooms/sounds/tap.mp3`)
     */
    public function __construct(
        public int $version,
        public array $files,
    ) {}

    /**
     * @param  array{version: int, sounds: array<value-of<RoomSound>, string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['version'], $data['sounds']);
    }

    /**
     * The URLs of some speakers' sounds by name, in the enum's order: what
     * `rooms/screen` plays (the screen's), or what `rooms/show` may play
     * (a phone's own, and the screen's for a room without a screen, ADR
     * 028 as amended 2026-11-19).
     *
     * @return array<value-of<RoomSound>, string>
     */
    public function urls(RoomSpeaker ...$speakers): array
    {
        $urls = [];

        foreach (RoomSound::cases() as $sound) {
            if (in_array($sound->speaker(), $speakers, true)) {
                $urls[$sound->value] = ContentAssets::url($this->files[$sound->value], $this->version);
            }
        }

        return $urls;
    }
}
