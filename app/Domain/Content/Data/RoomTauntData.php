<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentAssets;

/**
 * One taunt of the room's list, from `content/rooms/taunts.json`
 * (docs/architecture.md §12 "Room content", "Taunts (T2)", ADR 029
 * decisions 27–28): its key — what `room_taunts.taunt` stores and the
 * phrase's `rooms.taunts.say.{key}` in `lang/{az,en}.json` — and its
 * emoji, a Fluent Emoji (Color) file of `content/assets/fluent/`. A room
 * page gets the list as the prop `taunts`, each `{key, url}`; the URL
 * carries the file's `version`, as every content URL does. The phrase is
 * never stored and never content: each device says it in its own locale.
 *
 * @phpstan-type RoomTauntArray array{key: string, image: string}
 */
final readonly class RoomTauntData
{
    /** A taunt's key, as `room_taunts.taunt` holds it (string(32)). */
    public const string KEY_PATTERN = '/^[a-z_]{2,32}$/';

    /**
     * @param  string  $image  relative to `content/assets` (`fluent/1F422.svg`)
     */
    public function __construct(
        public string $key,
        public string $image,
        public int $version,
    ) {}

    /**
     * @param  RoomTauntArray  $data
     */
    public static function fromArray(array $data, int $version): self
    {
        return new self($data['key'], $data['image'], $version);
    }

    public function url(): string
    {
        return ContentAssets::url($this->image, $this->version);
    }

    /**
     * The prop `taunts` of `rooms/show` and `rooms/screen`: each key with
     * its emoji's URL, in the tray's order.
     *
     * @param  array<string, self>  $taunts  `ContentRepository::roomTaunts()`
     * @return list<array{key: string, url: string}>
     */
    public static function props(array $taunts): array
    {
        return array_values(array_map(static fn (self $taunt): array => ['key' => $taunt->key, 'url' => $taunt->url()], $taunts));
    }
}
