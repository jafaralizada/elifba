import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/hooks/use-translation';
import type { RoomPlayer, RoomRankedPlayer } from '@/rooms/contract';

/**
 * A ranking as the state gives it (docs/architecture.md §12 "Scoring and
 * what is kept"): the rows in the server's order, each place labelled from
 * the page's `place_labels` (the label of place n at n − 1), a player with
 * no points listed without a place. Plain; B4 designs it.
 */
export function Standing({
    ranking,
    players,
    me,
    placeLabels,
}: {
    ranking: RoomRankedPlayer[];
    players: RoomPlayer[];
    me: string | null;
    placeLabels: string[];
}) {
    const { t } = useTranslation();
    const names = new Map(players.map((player) => [player.id, player.name]));

    return (
        <ol className="flex flex-col gap-1">
            {ranking.map((row) => (
                <li
                    key={row.player_id}
                    className="flex min-h-10 items-center gap-3 rounded-lg border bg-card px-3 py-1.5"
                >
                    <span className="w-10 text-sm text-muted-foreground tabular-nums">
                        {row.place === null
                            ? '–'
                            : (placeLabels[row.place - 1] ?? '')}
                    </span>
                    <span className="min-w-0 flex-1 truncate">
                        {names.get(row.player_id) ?? t('rooms.show.nameless')}
                    </span>
                    {row.player_id === me && (
                        <Badge variant="secondary">{t('rooms.show.you')}</Badge>
                    )}
                    <span className="font-semibold tabular-nums">
                        {t('rooms.game.points', { count: row.points })}
                    </span>
                </li>
            ))}
        </ol>
    );
}
