import { Check, X } from 'lucide-react';
import { standingGroups } from '@/components/rooms/standing-groups';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type {
    RoomPlayer,
    RoomRankedPlayer,
    RoomResult,
} from '@/rooms/contract';

/**
 * A ranking as the state gives it (docs/frontend.md rule 15, "The
 * ranking"; architecture §12 "Scoring and what is kept"): the rows of one
 * place gathered under its label, shown once (`standingGroups()`), the
 * labels from the page's `place_labels`, players without points last
 * under «–». A row is the name («Oyunçu» for a name taken out), «siz» on
 * the viewer's own, and the points; in a reveal also the round's result
 * of each player (`results`), as the server judged it. Nothing is ranked,
 * judged or added up here.
 */
export function Standing({
    ranking,
    players,
    me,
    placeLabels,
    results = null,
    big = false,
}: {
    ranking: RoomRankedPlayer[];
    players: RoomPlayer[];
    me: string | null;
    placeLabels: string[];
    /** The round's results, in a reveal: a player without one did not answer. */
    results?: RoomResult[] | null;
    /** The screen's size. */
    big?: boolean;
}) {
    const { t } = useTranslation();
    const names = new Map(players.map((player) => [player.id, player.name]));
    const round = new Map(
        (results ?? []).map((result) => [result.player_id, result]),
    );

    return (
        <ol
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
                                    className={cn(
                                        'flex min-h-11 min-w-0 items-center gap-2 px-3 py-1.5',
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
                                        />
                                    )}
                                    <span
                                        className={cn(
                                            'w-16 shrink-0 text-right font-semibold tabular-nums',
                                            big && 'w-[12vh]',
                                        )}
                                    >
                                        {t('rooms.game.points', {
                                            count: row.points,
                                        })}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </li>
            ))}
        </ol>
    );
}

/** A player's result of the round, as the server judged it: ✓ and the points, ✗, or — without an answer. */
function RoundResult({
    result,
    big,
}: {
    result: RoomResult | null;
    big: boolean;
}) {
    const { t } = useTranslation();
    const icon = big ? 'size-[2.6vh]' : 'size-4';

    if (result === null) {
        return (
            <span
                className={cn(
                    'w-14 shrink-0 text-center text-muted-foreground',
                    big && 'w-[10vh]',
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
            className={cn(
                'inline-flex w-14 shrink-0 items-center justify-end gap-1 text-sm font-medium tabular-nums',
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
