<?php

namespace App\Domain\Rooms\Support;

use Random\Engine;
use SensitiveParameter;

/**
 * The random source of a room game's rounds (`RoomSeed::random()`): HMAC-
 * SHA-256 of a counter under a key, 8 bytes a step. The same key gives the
 * same stream, so a game's rounds can be told again from its seed; what a
 * device sees of the stream — the items shown and their ids — tells
 * nothing of the rest without the key, as a statistical generator's output
 * could (its state can be worked back from enough of it).
 */
final class RoomRandomEngine implements Engine
{
    private int $step = 0;

    public function __construct(
        #[SensitiveParameter] private readonly string $key,
    ) {}

    public function generate(): string
    {
        return substr(hash_hmac('sha256', pack('J', $this->step++), $this->key, true), 0, 8);
    }
}
