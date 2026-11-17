import { useEffect, useState } from 'react';
import type { ServerClock } from '@/rooms/clock';

/** How often a running round redraws its seconds. */
const TICK_MS = 200;

/**
 * The server's clock as the page estimates it (`clock.ts`), read again
 * every 200 ms while `running`: what the page shows of the time left. It
 * decides nothing — a phase changes only when a state says so.
 */
export function useServerNow(
    clock: ServerClock,
    running: boolean,
): number | null {
    const [now, setNow] = useState(() => clock.now());

    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = window.setInterval(() => setNow(clock.now()), TICK_MS);

        return () => window.clearInterval(timer);
    }, [clock, running]);

    return now;
}

/** Whole seconds until `moment`, never below zero. */
export function secondsUntil(moment: number, now: number | null): number {
    return now === null ? 0 : Math.max(0, Math.ceil((moment - now) / 1000));
}
