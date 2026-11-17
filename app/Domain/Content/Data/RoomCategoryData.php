<?php

namespace App\Domain\Content\Data;

/**
 * A group of words for `odd_one_out`, from `content/rooms/categories.json`
 * (docs/architecture.md §12 "Room content", ADR 023 decision 7): a key and
 * at least 8 common words, lowercase, each in no other group, so the word
 * that does not belong is never arguable (`ContentValidator`). The groups
 * are far apart in meaning; a round never names its group.
 */
final readonly class RoomCategoryData
{
    /**
     * @param  list<string>  $words  as written in the file: lowercase
     */
    public function __construct(
        public string $key,
        public array $words,
    ) {}

    /**
     * @param  array{key: string, words: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['key'], $data['words']);
    }
}
