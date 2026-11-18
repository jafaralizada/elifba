import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { index, past as pastRoute, show } from '@/routes/rooms';
import type { RoomMechanic } from '@/rooms/contract';

/** A past room: its day, its games and the account's own row of its standing (null for a host who did not play). */
type PastRoom = {
    id: string;
    date: string;
    host: boolean;
    players: number;
    games: { mechanic: RoomMechanic; difficulty: number }[];
    mine: { points: number; place: string | null } | null;
};

type Props = { past: PastRoom[] };

/**
 * «Keçmiş oyunlar» (docs/frontend.md rule 15; architecture §12 "Games
 * first", ADR 026 decision 14): the rooms the account was in that are
 * closed or that it left, newest first, with their games and the
 * account's own place, labelled on the server — as «Otaqlar» listed them
 * before, on a page of their own. One row per room; no total across
 * rooms. G2 keeps the list as it was; G3 names the page and gives it its
 * entry in the navigation.
 */
export default function RoomsPast({ past }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('rooms.index.past_rooms')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-4 px-4 pt-4 pb-10 md:px-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('rooms.index.past_rooms')}
                </h1>

                {past.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('rooms.index.empty')}
                    </p>
                )}

                <ul className="flex flex-col gap-2">
                    {past.map((room) => (
                        <li key={room.id}>
                            <Link
                                href={show(room.id)}
                                className="flex min-h-14 items-center gap-3 rounded-xl border bg-card px-4 py-3 transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            >
                                <span className="flex min-w-0 flex-1 flex-col gap-1.5">
                                    <span className="font-semibold">
                                        {room.date}
                                    </span>
                                    {room.games.length > 0 && (
                                        <span className="flex flex-wrap gap-1">
                                            {room.games.map(
                                                (game, position) => (
                                                    <span
                                                        key={position}
                                                        className="rounded-md bg-muted px-1.5 py-0.5 text-xs"
                                                    >
                                                        {t('rooms.index.game', {
                                                            game: t(
                                                                `rooms.mechanics.${game.mechanic}.name`,
                                                            ),
                                                            difficulty: t(
                                                                `rooms.difficulty.${game.difficulty}`,
                                                            ),
                                                        })}
                                                    </span>
                                                ),
                                            )}
                                        </span>
                                    )}
                                    <span className="text-sm text-muted-foreground">
                                        {pastLine(room, t)}
                                    </span>
                                </span>
                                <ChevronRight
                                    className="size-5 shrink-0 text-muted-foreground"
                                    aria-hidden
                                />
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </>
    );
}

/** A past room's muted line: the players, «Siz açmısınız», the account's own place and points. */
function pastLine(
    room: PastRoom,
    t: ReturnType<typeof useTranslation>['t'],
): string {
    const parts = [t('rooms.index.players', { count: room.players })];

    if (room.host) {
        parts.push(t('rooms.index.hosted'));
    }

    if (room.mine !== null && room.games.length > 0) {
        parts.push(
            room.mine.place === null
                ? t('rooms.index.no_points')
                : t('rooms.index.place_points', {
                      place: room.mine.place,
                      points: t('rooms.game.points', {
                          count: room.mine.points,
                      }),
                  }),
        );
    }

    return parts.join(' · ');
}

RoomsPast.layout = {
    breadcrumbs: [
        { title: 'nav.rooms', href: index() },
        { title: 'rooms.index.past_rooms', href: pastRoute() },
    ],
};
