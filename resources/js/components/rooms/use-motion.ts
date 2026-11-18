import { useEffect, useState } from 'react';
import { reducedMotion } from '@/components/rooms/motion-dom';
import {
    countUpValue,
    roomMoment,
    sawBegin,
    stripMoves,
} from '@/components/rooms/motion';
import type {
    RoomGame,
    RoomRankedPlayer,
    RoomRound,
    RoomState,
} from '@/rooms/contract';

/**
 * Whether the page animates what `state` shows (docs/frontend.md rule 15
 * "Motion", ADR 025): `phase` for the moment (a round's phase, a game's
 * end), `round` for the round itself, `strip` for the rivalry strip (a
 * reveal, ADR 027). All are false on the moment the page first drew —
 * opened, reloaded or joined late — and `phase` and `round` true for every
 * later one; a state fetched again is the same moment.
 */
export type RoomMotion = { phase: boolean; round: boolean; strip: boolean };

export function useRoomMotion(state: RoomState | null): RoomMotion {
    const [first] = useState(() => roomMoment(state));
    const now = roomMoment(state);

    return {
        phase: sawBegin(first, now, 'phase'),
        round: sawBegin(first, now, 'round'),
        strip: stripMoves(first, now),
    };
}

/**
 * A number counting from `from` to `to` (both the server's) after `delay`
 * over `duration` ms when `enabled`; otherwise, and under reduced motion,
 * `to` at once. The same numbers again count nothing again.
 */
export function useCountUp(
    to: number,
    {
        from = 0,
        enabled,
        delay = 0,
        duration = 600,
    }: { from?: number; enabled: boolean; delay?: number; duration?: number },
): number {
    const run = enabled && from !== to;
    const [value, setValue] = useState(() =>
        run && !reducedMotion() ? from : to,
    );

    useEffect(() => {
        if (!run || reducedMotion()) {
            setValue(to);

            return;
        }

        let frame = 0;
        const start = performance.now() + delay;
        const step = (time: number) => {
            const progress = (time - start) / duration;

            setValue(countUpValue(from, to, progress));

            if (progress < 1) {
                frame = window.requestAnimationFrame(step);
            }
        };

        frame = window.requestAnimationFrame(step);

        return () => window.cancelAnimationFrame(frame);
    }, [run, from, to, delay, duration]);

    return value;
}

type ShownReveal = { game: string; index: number; ranking: RoomRankedPlayer[] };

/**
 * The ranking of the reveal the page showed before this one, when this is
 * a reveal and that one was the round just before it: the order a
 * ranking's rows move from (`rankShift()`). Null otherwise — the game's
 * first reveal, or a page that did not show the one before.
 */
export function usePreviousReveal(
    game: RoomGame,
    round: RoomRound,
    ranking: RoomRankedPlayer[],
): RoomRankedPlayer[] | null {
    const [shown, setShown] = useState<{
        last: ShownReveal | null;
        before: ShownReveal | null;
    }>({ last: null, before: null });

    if (
        round.phase === 'reveal' &&
        (shown.last?.game !== game.id || shown.last.index !== round.index)
    ) {
        // A new reveal: remember it, and the one before it.
        setShown({
            before: shown.last,
            last: { game: game.id, index: round.index, ranking },
        });
    }

    const before = shown.before;

    return round.phase === 'reveal' &&
        before !== null &&
        before.game === game.id &&
        before.index === round.index - 1
        ? before.ranking
        : null;
}
