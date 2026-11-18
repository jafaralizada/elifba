<?php

namespace App\Domain\Content\Enums;

/**
 * Which device plays a room sound (docs/architecture.md §12 "Room
 * content", ADR 025 "Sound"): the screen is the room's speaker; a phone
 * plays quiet cues of its own, and in a room without a screen (the state's
 * `room.screen` false, ADR 028 as amended 2026-11-19) the room's too. The
 * screen is sent its own sounds; a phone its own and the screen's, which
 * it plays only while the room has no screen.
 */
enum RoomSpeaker: string
{
    /** `rooms/screen`: the moments everybody shares. */
    case Screen = 'screen';

    /** `rooms/show`: the viewer's own taps, answer and verdict. */
    case Phone = 'phone';
}
