<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Support\ContentAssets;

/**
 * A shape a `recall_order` round may show, from `content/rooms/shapes.json`
 * (docs/architecture.md §12 "Room content", ADR 023): a key and a plain
 * SVG of our own under `content/assets/rooms/`, told apart from the others
 * by its form, with no digit, letter or text (`ContentValidator`). Its URL
 * carries the file's `version`, as every content URL does.
 */
final readonly class RoomShapeData
{
    /**
     * @param  string  $image  relative to `content/assets` (`rooms/ulduz.svg`)
     * @param  int  $version  `shapes.json`'s, for the URL
     */
    public function __construct(
        public string $key,
        public string $image,
        public int $version,
    ) {}

    /**
     * @param  array{key: string, image: string}  $data
     */
    public static function fromArray(array $data, int $version): self
    {
        return new self($data['key'], $data['image'], $version);
    }

    /**
     * The picture's URL, as a round stores it (`Item.asset`).
     */
    public function url(): string
    {
        return ContentAssets::url($this->image, $this->version);
    }
}
