import { cn } from '@/lib/utils';
import { timeLeft } from '@/rooms/clock';

/** The last stretch of an answer, when the bar turns red. */
export const URGENT_MS = 3000;

/**
 * How much of the phase is left (docs/frontend.md rule 15, "The time
 * bar"): a track with a fill scaled from its start, from the estimated
 * server clock (`now`, from `useServerNow()`), never the device's. It is
 * for show and decides nothing; the seconds beside it say the same in
 * words, so it is hidden from screen readers.
 */
export function TimeBar({
    stretch,
    now,
    urgent = false,
    className,
}: {
    stretch: { from: number; to: number } | null;
    now: number | null;
    /** Turns red in the last 3 s (the answer). */
    urgent?: boolean;
    className?: string;
}) {
    if (stretch === null) {
        return <div aria-hidden className={cn('h-2', className)} />;
    }

    const left = timeLeft(stretch.from, stretch.to, now);
    const hurry =
        urgent && now !== null && stretch.to - now <= URGENT_MS && left > 0;

    return (
        <div
            aria-hidden
            className={cn(
                'h-2 overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            <div
                className={cn(
                    'h-full origin-left rounded-full transition-transform duration-200 ease-linear motion-reduce:transition-none',
                    hurry ? 'bg-destructive' : 'bg-primary',
                )}
                style={{ transform: `scaleX(${left})` }}
            />
        </div>
    );
}
