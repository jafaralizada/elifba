<?php

namespace App\Domain\Content\Enums;

/**
 * The sounds of a room game (docs/architecture.md §12 "Room content", ADR
 * 025 "Sound"), one file each in `content/rooms/sounds.json`. The page
 * decides when one plays (`components/rooms/sound-cues.ts`, which names
 * the same values); nothing audible before the reveal tells right from
 * wrong: `Sent` is one sound for every answer, and `Right` and `Wrong`
 * play only from the reveal, on the viewer's own phone. Separate from the
 * children's `Sound` and `content/sounds.json`.
 */
enum RoomSound: string
{
    /** The screen: each second of the countdown. */
    case Count = 'count';

    /** The screen: the study opens. */
    case Study = 'study';

    /** The screen: the question opens. */
    case Question = 'question';

    /** The screen: each of the last three seconds of an answer. */
    case Tick = 'tick';

    /** The screen: the round closes and the reveal opens, whatever anyone answered. */
    case Close = 'close';

    /** The screen: the reveal's ranking comes in. */
    case Ranking = 'ranking';

    /** The screen: the final's roll, ending where the first place opens. */
    case Roll = 'roll';

    /** The screen: the fanfare when the final's first place opens. */
    case Fanfare = 'fanfare';

    /** A phone: the viewer's own tap, the same for every item. */
    case Tap = 'tap';

    /** A phone: a tap taken back («Geri», a cell tapped again). */
    case Undo = 'undo';

    /** A phone: the viewer's answer is in, the same for every answer. */
    case Sent = 'sent';

    /** A phone, from the reveal: the viewer's answer was right. */
    case Right = 'right';

    /** A phone, from the reveal: the viewer's answer was wrong. */
    case Wrong = 'wrong';

    public function speaker(): RoomSpeaker
    {
        return match ($this) {
            self::Tap, self::Undo, self::Sent, self::Right, self::Wrong => RoomSpeaker::Phone,
            default => RoomSpeaker::Screen,
        };
    }

    /**
     * The sounds one speaker plays, in the enum's order.
     *
     * @return list<self>
     */
    public static function of(RoomSpeaker $speaker): array
    {
        return array_values(array_filter(self::cases(), static fn (self $sound): bool => $sound->speaker() === $speaker));
    }
}
