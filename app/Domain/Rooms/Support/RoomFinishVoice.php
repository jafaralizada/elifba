<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Content\Support\VoiceClips;

/**
 * The end phrases the big screen says after the final's fanfare (docs/
 * architecture.md §12 "Room games", ADR 030), as a home board ends a game:
 * «Əla oynadın!» after one winner's name, «Əla oynadınız!» after a shared
 * first place's — the board's own built-in clips (`voice.json`'s `finish`
 * and `finish_class`, ADR 016 decision 7), never a new phrase. Public
 * content URLs, or null while a clip is not made. They name nobody; the
 * name comes by ids (`RoomMemberVoice`).
 */
final readonly class RoomFinishVoice
{
    public function __construct(private VoiceClips $clips) {}

    /**
     * @return array{one: string|null, many: string|null}
     */
    public function urls(): array
    {
        return [
            'one' => $this->clips->url($this->clips->finish()),
            'many' => $this->clips->url($this->clips->finishClass()),
        ];
    }
}
