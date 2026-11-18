<?php

namespace App\Domain\Content\Enums;

/**
 * Which device plays a room sound (docs/architecture.md §12 "Room
 * content", ADR 025 "Sound"): the screen is the room's speaker; a phone
 * plays only quiet cues of its own. A page is sent the sounds of its own
 * speaker and no other, so a phone cannot ring a room's moment.
 */
enum RoomSpeaker: string
{
    /** `rooms/screen`: the moments everybody shares. */
    case Screen = 'screen';

    /** `rooms/show`: the viewer's own taps, answer and verdict. */
    case Phone = 'phone';
}
