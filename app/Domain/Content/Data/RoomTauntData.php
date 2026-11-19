<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\TauntVoices;

/**
 * One taunt of the room's list, from `content/rooms/taunts.json`
 * (docs/architecture.md §12 "Room content", "Taunts (T2)", "Taunts in the
 * middle, said aloud (T3)", ADR 029 decisions 27–28, 49–50): its key — what
 * `room_taunts.taunt` stores and the phrase's `rooms.taunts.say.{key}` in
 * `lang/{az,en}.json` —, its emoji, a Fluent Emoji (Color) file of
 * `content/assets/fluent/`, and from T4 its `voice`, the audio tag the
 * board's voice says its Azerbaijani phrase after (`TauntVoices`). A room
 * page gets the list as the prop `taunts`, each `{key, url, voice}`: the
 * emoji's URL and the clip's, or null while the clip is not made; both
 * carry the file's `version`, as every content URL does. The phrase is
 * never stored and never content: each device draws it in its own locale,
 * and the one clip says it in Azerbaijani in every locale.
 *
 * @phpstan-type RoomTauntArray array{key: string, image: string, voice: string}
 */
final readonly class RoomTauntData
{
    /** A taunt's key, as `room_taunts.taunt` holds it (string(32)). */
    public const string KEY_PATTERN = '/^[a-z_]{2,32}$/';

    /** A taunt's audio tag, without its brackets (ADR 029 decision 50). */
    public const string VOICE_PATTERN = '/^[a-z]{3,24}$/';

    /**
     * @param  string  $image  relative to `content/assets` (`fluent/1F422.svg`)
     * @param  string  $voice  the audio tag of its clip (`mischievously`)
     */
    public function __construct(
        public string $key,
        public string $image,
        public string $voice,
        public int $version,
    ) {}

    /**
     * @param  RoomTauntArray  $data
     */
    public static function fromArray(array $data, int $version): self
    {
        return new self($data['key'], $data['image'], $data['voice'], $version);
    }

    public function url(): string
    {
        return ContentAssets::url($this->image, $this->version);
    }

    /**
     * The prop `taunts` of `rooms/show` and `rooms/screen`: each key with
     * its emoji's URL and its clip's (null until `content:voice` made it),
     * in the fan's order.
     *
     * @param  array<string, self>  $taunts  `ContentRepository::roomTaunts()`
     * @return list<array{key: string, url: string, voice: string|null}>
     */
    public static function props(array $taunts, TauntVoices $voices): array
    {
        return array_values(array_map(static fn (self $taunt): array => [
            'key' => $taunt->key,
            'url' => $taunt->url(),
            'voice' => $voices->url($taunt),
        ], $taunts));
    }
}
