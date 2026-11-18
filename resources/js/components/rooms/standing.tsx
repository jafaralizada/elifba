import { Check, X } from 'lucide-react';
import { useLayoutEffect, useRef } from 'react';
import { rankShift } from '@/components/rooms/motion';
import { slideRows } from '@/components/rooms/motion-dom';
import { standingGroups } from '@/components/rooms/standing-groups';
import { useCountUp } from '@/components/rooms/use-motion';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type {
    RoomPlayer,
    RoomRankedPlayer,
    RoomResult,
} from '@/rooms/contract';

/** In a reveal: the rows start moving this long after they come in, for this long. */
const MOVE_AFTER_MS = 250;
const MOVE_MS = 700;

/**
 * A ranking as the state gives it (docs/frontend.md rule 15, "The
 * ranking"; architecture §12 "Scoring and what is kept"): the rows of one
 * place gathered under its label, shown once (`standingGroups()`), the
 * labels from the page's `place_labels`, players without points last
 * under «–». A row is the name («Oyunçu» for a name taken out), «siz» on
 * the viewer's own, and the points; in a reveal also the round's result
 * of each player (`results`), as the server judged it. Nothing is ranked,
 * judged or added up here.
 *
 * In a reveal the page saw begin (`motion`, ADR 025) the rows stand where
 * the previous reveal had them, then move to their places while each total
 * counts up from its number before the round (`points − result.points`)
 * to the server's, and the round's results pop in. It is a plain list:
 * the final of a game is `FinalStanding`.
 */
export function Standing({
    ranking,
    players,
    me,
    placeLabels,
    results = null,
    big = false,
    motion = null,
}: {
    ranking: RoomRankedPlayer[];
    players: RoomPlayer[];
    me: string | null;
    placeLabels: string[];
    /** The round's results, in a reveal: a player without one did not answer. */
    results?: RoomResult[] | null;
    /** The screen's size. */
    big?: boolean;
    /** A reveal the page saw begin: the previous reveal's order, and when the rows start. */
    motion?: { previous: RoomRankedPlayer[] | null; delay: number } | null;
}) {
    const { t } = useTranslation();
    const names = new Map(players.map((player) => [player.id, player.name]));
    const round = new Map(
        (results ?? []).map((result) => [result.player_id, result]),
    );
    const list = useRef<HTMLOListElement>(null);
    const moved = useRef(false);
    const moves =
        motion === null
            ? null
            : { delay: motion.delay + MOVE_AFTER_MS, duration: MOVE_MS };

    // Once per reveal: the rows move from the previous order to this one.
    useLayoutEffect(() => {
        if (motion === null || moved.current || list.current === null) {
            return;
        }

        moved.current = true;
        slideRows(list.current, rankShift(motion.previous, ranking), {
            delay: motion.delay + MOVE_AFTER_MS,
            duration: MOVE_MS,
        });
    }, [motion, ranking]);

    return (
        <ol
            ref={list}
            className={cn(
                'flex flex-col overflow-hidden rounded-xl border bg-card',
                big && 'rounded-2xl',
            )}
        >
            {standingGroups(ranking, placeLabels).map((group) => (
                <li
                    key={group.place ?? 'none'}
                    className="grid grid-cols-[3.25rem_minmax(0,1fr)] border-b last:border-b-0 data-[big=true]:grid-cols-[11vh_minmax(0,1fr)]"
                    data-big={big}
                >
                    <span
                        className={cn(
                            'flex items-start justify-center border-r bg-muted/40 pt-3 text-sm font-semibold',
                            big && 'pt-[2vh] text-[2.8vh]',
                            group.place === 1 && 'text-foreground',
                            group.place !== 1 && 'text-muted-foreground',
                        )}
                    >
                        {group.label ?? '–'}
                    </span>
                    <ul className="flex min-w-0 flex-col">
                        {group.rows.map((row) => {
                            const mine = row.player_id === me;
                            const result = round.get(row.player_id);

                            return (
                                <li
                                    key={row.player_id}
                                    data-player={row.player_id}
                                    className={cn(
                                        'relative flex min-h-11 min-w-0 items-center gap-2 bg-card px-3 py-1.5',
                                        big &&
                                            'min-h-[7vh] gap-[1.6vh] px-[2vh] text-[2.8vh]',
                                        mine && 'bg-accent',
                                    )}
                                >
                                    <span className="min-w-0 flex-1 truncate">
                                        {names.get(row.player_id) ??
                                            t('rooms.show.nameless')}
                                    </span>
                                    {mine && (
                                        <span className="shrink-0 rounded-full bg-background px-2 py-0.5 text-xs font-medium text-muted-foreground">
                                            {t('rooms.show.you')}
                                        </span>
                                    )}
                                    {results !== null && (
                                        <RoundResult
                                            result={result ?? null}
                                            big={big}
                                            delay={
                                                motion === null
                                                    ? null
                                                    : motion.delay + 120
                                            }
                                        />
                                    )}
                                    <Total
                                        points={row.points}
                                        from={
                                            row.points - (result?.points ?? 0)
                                        }
                                        moves={moves}
                                        big={big}
                                    />
                                </li>
                            );
                        })}
                    </ul>
                </li>
            ))}
        </ol>
    );
}

/** A row's total: the server's points, counted up from the number before the round while the rows move. */
function Total({
    points,
    from,
    moves,
    big,
}: {
    points: number;
    from: number;
    moves: { delay: number; duration: number } | null;
    big: boolean;
}) {
    const { t } = useTranslation();
    const shown = useCountUp(points, {
        from,
        enabled: moves !== null,
        delay: moves?.delay,
        duration: moves?.duration,
    });

    return (
        <span
            className={cn(
                'min-w-16 shrink-0 text-right font-semibold whitespace-nowrap tabular-nums',
                big && 'min-w-[12vh]',
            )}
        >
            {t('rooms.game.points', { count: shown })}
        </span>
    );
}

/** A player's result of the round, as the server judged it: ✓ and the points, ✗, or — without an answer. */
export function RoundResult({
    result,
    big,
    delay,
}: {
    result: RoomResult | null;
    big: boolean;
    /** In a reveal the page saw begin: when it pops in. */
    delay: number | null;
}) {
    const { t } = useTranslation();
    const icon = big ? 'size-[2.6vh]' : 'size-4';
    const pop = delay === null ? undefined : { animationDelay: `${delay}ms` };

    if (result === null) {
        return (
            <span
                style={pop}
                className={cn(
                    'w-14 shrink-0 text-center text-muted-foreground',
                    big && 'w-[10vh]',
                    pop && 'motion-safe:animate-room-pop',
                )}
                title={t('rooms.game.no_answer_label')}
            >
                <span aria-hidden>—</span>
                <span className="sr-only">
                    {t('rooms.game.no_answer_label')}
                </span>
            </span>
        );
    }

    return (
        <span
            style={pop}
            className={cn(
                'inline-flex w-14 shrink-0 items-center justify-end gap-1 text-sm font-medium tabular-nums',
                pop && 'motion-safe:animate-room-pop',
                big && 'w-[10vh] text-[2.4vh]',
                result.correct
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-red-600 dark:text-red-400',
            )}
        >
            {result.correct ? (
                <Check aria-hidden className={icon} />
            ) : (
                <X aria-hidden className={icon} />
            )}
            <span className="sr-only">
                {result.correct
                    ? t('rooms.game.correct')
                    : t('rooms.game.wrong')}
            </span>
            {t('rooms.game.round_points', { count: result.points })}
        </span>
    );
}
