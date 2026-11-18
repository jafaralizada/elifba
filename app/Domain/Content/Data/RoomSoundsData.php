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
     * The URLs of one speaker's sounds by name: what `rooms/screen` or
     * `rooms/show` plays, and nothing of the other device.
     *
     * @return array<value-of<RoomSound>, string>
     */
    public function urls(RoomSpeaker $speaker): array
    {
        $urls = [];

        foreach (RoomSound::of($speaker) as $sound) {
            $urls[$sound->value] = ContentAssets::url($this->files[$sound->value], $this->version);
        }

        return $urls;
    }
}
