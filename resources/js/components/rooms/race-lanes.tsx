import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { rankShift } from '@/components/rooms/motion';
import { reducedMotion, slideSlots } from '@/components/rooms/motion-dom';
import type { RivalPair } from '@/components/rooms/rivalry';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import { RoundResult } from '@/components/rooms/standing';
import { SpentMark } from '@/components/rooms/taunt-parts';
import { useCountUp } from '@/components/rooms/use-motion';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type {
    RoomPlayer,
    RoomRankedPlayer,
    RoomResult,
    RoomSpent,
} from '@/rooms/contract';

/** In a reveal: the rows start moving this long after they come in, for this long (as `Standing`'s). */
const MOVE_AFTER_MS = 250;
const MOVE_MS = 700;
/** One column up to this many rows, two above it. */
const ONE_COLUMN = 10;

/**
 * The screen's race (docs/frontend.md rule 15 "Rivals face to face",
 * architecture §12 "Rivals face to face (P2)", ADR 027 decision 13): in a
 * reveal with three players or more, one lane per row of the ranking in
 * the server's order — the place as a filled label once per place (the
 * strip's two sides in their colours, the others muted), the name with 👑
 * at the first place and «çıxıb», a bar of the row's points against the
 * first row's, the round's result as the server judged it, and the total.
 * The strip's two rows are tinted in their side's colour. One column up
 * to ten rows, two from eleven. Nothing is ranked, judged or added up here.
 *
 * In a reveal the page saw begin (`motion`, ADR 025) the lanes stand where
 * the previous reveal had them, then move to their places while each bar
 * grows and each total counts up from its number before the round
 * (`points − result.points + spent`: from T2 the taunts counted at this
 * close are added back, and shown beside the round's result as «−:count»,
 * ADR 029 decision 6) to the server's.
 */
export function RaceLanes({
    ranking,
    players,
    placeLabels,
    results,
    spent = [],
    pair,
    icons,
    motion,
}: {
    ranking: RoomRankedPlayer[];
    players: RoomPlayer[];
    placeLabels: string[];
    results: RoomResult[];
    /** The taunts counted at this close (T2). */
    spent?: RoomSpent[];
    /** The strip's pair: its two rows are tinted. */
    pair: RivalPair | null;
    icons: RivalryIcons | null;
    /** A reveal the page saw begin: the previous reveal's order, and when the rows start. */
    motion: { previous: RoomRankedPlayer[] | null; delay: number } | null;
}) {
    const { t } = useTranslation();
    const names = new Map(players.map((player) => [player.id, player]));
    const round = new Map(results.map((result) => [result.player_id, result]));
    const paid = new Map(spent.map((cost) => [cost.player_id, cost.count]));
    const root = useRef<HTMLDivElement>(null);
    const moved = useRef(false);
    const moves =
        motion === null
            ? null
            : { delay: motion.delay + MOVE_AFTER_MS, duration: MOVE_MS };
    const most = ranking[0]?.points ?? 0;
    const columns =
        ranking.length > ONE_COLUMN
            ? [
                  ranking.slice(0, Math.ceil(ranking.length / 2)),
                  ranking.slice(Math.ceil(ranking.length / 2)),
              ]
            : [ranking];
    const side = (row: RoomRankedPlayer): 'left' | 'right' | null =>
        row.player_id === pair?.left?.player_id
            ? 'left'
            : row.player_id === pair?.right?.player_id
              ? 'right'
              : null;
    const labelled = new Set<string>();
    let place: number | null | undefined;

    for (const row of ranking) {
        if (row.place !== place) {
            labelled.add(row.player_id);
            place = row.place;
        }
    }

    // Once per reveal: the lanes move from the previous order to this one.
    useLayoutEffect(() => {
        if (motion === null || moved.current || root.current === null) {
            return;
        }

        moved.current = true;
        slideSlots(root.current, rankShift(motion.previous, ranking), {
            delay: motion.delay + MOVE_AFTER_MS,
            duration: MOVE_MS,
        });
    }, [motion, ranking]);

    return (
        <div
            ref={root}
            className={cn(
                'grid items-start gap-[1.6vh]',
                columns.length === 2 && 'grid-cols-2',
            )}
        >
            {columns.map((rows, column) => (
                <ol
                    key={column}
                    className="flex flex-col overflow-hidden rounded-[2vh] border bg-card"
                >
                    {rows.map((row) => {
                        const tint = side(row);
                        const result = round.get(row.player_id) ?? null;
                        const cost = paid.get(row.player_id) ?? 0;
                        const from = row.points - (result?.points ?? 0) + cost;
                        const label =
                            row.place === null
                                ? null
                                : (placeLabels[row.place - 1] ?? null);

                        return (
                            <li
                                key={row.player_id}
                                data-player={row.player_id}
                                className={cn(
                                    'relative grid min-h-[5.4vh] grid-cols-[7vh_minmax(0,1.3fr)_minmax(0,1fr)_14vh_6vh] items-center gap-[1.4vh] border-b bg-card px-[1.8vh] py-[0.3vh] text-[2.5vh] last:border-b-0',
                                    tint === 'left' &&
                                        'bg-[linear-gradient(90deg,var(--rival-left-tint),var(--card)_60%)]',
                                    tint === 'right' &&
                                        'bg-[linear-gradient(90deg,var(--rival-right-tint),var(--card)_60%)]',
                                )}
                            >
                                <span>
                                    {labelled.has(row.player_id) &&
                                        (label === null ? (
                                            <span className="px-[0.9vh] text-muted-foreground">
                                                –
                                            </span>
                                        ) : (
                                            <span
                                                className={cn(
                                                    'inline-block rounded-[0.8vh] px-[0.9vh] text-[2vh] leading-normal font-bold tabular-nums',
                                                    tint === 'left'
                                                        ? 'bg-rival-left text-white'
                                                        : tint === 'right'
                                                          ? 'bg-rival-right text-white'
                                                          : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {label}
                                            </span>
                                        ))}
                                </span>
                                <span className="flex min-w-0 items-center gap-[0.8vh]">
                                    <span
                                        className={cn(
                                            'min-w-0 truncate',
                                            tint !== null && 'font-semibold',
                                        )}
                                    >
                                        {names.get(row.player_id)?.name ??
                                            t('rooms.show.nameless')}
                                    </span>
                                    {row.place === 1 && icons !== null && (
                                        <img
                                            src={icons.leader}
                                            alt=""
                                            aria-hidden
                                            draggable={false}
                                            className="size-[2.8vh] shrink-0 select-none"
                                        />
                                    )}
                                    {names.get(row.player_id)?.present ===
                                        false && (
                                        <span className="shrink-0 rounded-full border px-[0.8vh] text-[1.6vh] text-muted-foreground">
                                            {t('rooms.rivalry.away')}
                                        </span>
                                    )}
                                </span>
                                <LaneBar
                                    points={row.points}
                                    from={from}
                                    most={most}
                                    tint={tint}
                                    moves={moves}
                                />
                                <span className="flex items-center justify-end gap-[0.6vh]">
                                    <RoundResult
                                        result={result}
                                        big
                                        delay={
                                            motion === null
                                                ? null
                                                : motion.delay + 120
                                        }
                                    />
                                    {cost > 0 && (
                                        <SpentMark
                                            count={cost}
                                            className="text-[2.2vh]"
                                        />
                                    )}
                                </span>
                                <LaneTotal
                                    points={row.points}
                                    from={from}
                                    moves={moves}
                                />
                            </li>
                        );
                    })}
                </ol>
            ))}
        </div>
    );
}

/**
 * A lane's bar: the row's points against the first row's, in its side's
 * gradient or the foreground at a third; it grows from the round before
 * while the lanes move.
 */
function LaneBar({
    points,
    from,
    most,
    tint,
    moves,
}: {
    points: number;
    from: number;
    most: number;
    tint: 'left' | 'right' | null;
    moves: { delay: number; duration: number } | null;
}) {
    const [grown, setGrown] = useState(
        () => moves === null || from === points || reducedMotion(),
    );

    useEffect(() => {
        if (grown || moves === null) {
            return;
        }

        const timer = window.setTimeout(() => setGrown(true), moves.delay);

        return () => window.clearTimeout(timer);
    }, [grown, moves]);

    const width = (value: number) =>
        most <= 0 ? 0 : Math.max(0, Math.min(100, (100 * value) / most));

    return (
        <span
            aria-hidden
            className="block h-[1.4vh] overflow-hidden rounded-full bg-muted"
        >
            <span
                className={cn(
                    'block h-full rounded-full',
                    moves !== null &&
                        'transition-[width] duration-700 ease-[cubic-bezier(0.2,0.8,0.2,1)] motion-reduce:transition-none',
                    tint === 'left'
                        ? 'bg-[linear-gradient(180deg,var(--rival-left-hi),var(--rival-left))]'
                        : tint === 'right'
                          ? 'bg-[linear-gradient(180deg,var(--rival-right-hi),var(--rival-right))]'
                          : 'bg-foreground/30',
                )}
                style={{ width: `${width(grown ? points : from)}%` }}
            />
        </span>
    );
}

/** A lane's total: the server's points, counted up from the round before while the lanes move. */
function LaneTotal({
    points,
    from,
    moves,
}: {
    points: number;
    from: number;
    moves: { delay: number; duration: number } | null;
}) {
    const shown = useCountUp(points, {
        from,
        enabled: moves !== null,
        delay: moves?.delay,
        duration: moves?.duration,
    });

    return (
        <span className="text-right font-bold whitespace-nowrap tabular-nums">
            {shown}
        </span>
    );
}
