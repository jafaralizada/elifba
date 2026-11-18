<?php

namespace App\Domain\Rooms\Data;

/**
 * The state's `voice` (docs/architecture.md §12 "The state's voice", ADR
 * 030 "The state"; zod `RoomVoice`): whether the host has the room's voice
 * on, and whether the viewer's own account has accepted the voice's
 * sentence. Null in the state while the instance's voice is off and in a
 * closed room. Who is in the voice, whose microphone is open and who
 * speaks are never here.
 *
 * @phpstan-type RoomVoiceArray array{on: bool, agreed: bool}
 */
final readonly class RoomVoiceData
{
    public function __construct(
        public bool $on,
        public bool $agreed,
    ) {}

    /**
     * @param  RoomVoiceArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['on'], $data['agreed']);
    }

    /**
     * @return RoomVoiceArray
     */
    public function toArray(): array
    {
        return [
            'on' => $this->on,
            'agreed' => $this->agreed,
        ];
    }
}
