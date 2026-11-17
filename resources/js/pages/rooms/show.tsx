import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import {
    close,
    destroy,
    entrance,
    index,
    leave,
    state as stateRoute,
    update,
} from '@/routes/rooms';
import { destroy as remove } from '@/routes/rooms/members';
import { destroy as forget } from '@/routes/rooms/name';
import type { RoomState } from '@/rooms/contract';
import { RoomState as RoomStateSchema } from '@/rooms/contract';
import type { RoomWatch } from '@/rooms/live';
import { watchRoom } from '@/rooms/live';
import { connectRoomEcho, roomChannel } from '@/rooms/socket';

type Props = {
    room: { id: string; status: 'open' | 'closed' };
    /** Null for a player who left an open room: no live state for them. */
    state: RoomState | null;
    can: { host: boolean; leave: boolean; delete: boolean; forget: boolean };
};

/**
 * A room's page (architecture §12 "Rooms and joining", "The race"): while
 * the room is open, the lobby — the code, the players by name with the
 * viewer's own row marked, and the host's lock, removal and close; once
 * closed, the players and the ways out. The page reads the state when
 * `room.changed` brings a higher revision, or every 3 s without a socket;
 * it sends no client event. Plain until B4 designs it; B3 adds the game.
 */
export default function RoomsShow({ room, state: initial, can }: Props) {
    const { t } = useTranslation();
    const [state, setState] = useState(initial);
    const live = room.status === 'open' && initial !== null;
    // One socket for the page's life, not one per reload of its props.
    const watchRef = useRef<RoomWatch | null>(null);
    const firstRevision = useRef(initial?.revision ?? 0);
    // Two fetches may answer out of order: an older state never covers a newer one.
    const shown = useRef(initial?.revision ?? 0);

    useEffect(() => {
        setState(initial);

        if (initial !== null) {
            shown.current = initial.revision;
            watchRef.current?.seen(initial.revision);
        }
    }, [initial]);

    useEffect(() => {
        if (!live) {
            return;
        }

        let echo: ReturnType<typeof connectRoomEcho> | null = null;

        try {
            echo = connectRoomEcho();
        } catch {
            echo = null;
        }

        const watch = watchRoom({
            revision: firstRevision.current,
            channel: echo === null ? null : roomChannel(echo, room.id),
            refresh: () => {
                void fetch(stateRoute.url(room.id), {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                })
                    .then(async (response) => {
                        if (!response.ok) {
                            // Removed, gone or closed: the page says which.
                            router.reload();

                            return;
                        }

                        const next = RoomStateSchema.safeParse(
                            await response.json(),
                        );

                        if (
                            !next.success ||
                            next.data.revision < shown.current
                        ) {
                            return;
                        }

                        watch.seen(next.data.revision);
                        shown.current = next.data.revision;

                        if (next.data.room.status !== room.status) {
                            router.reload();
                        } else {
                            setState(next.data);
                        }
                    })
                    .catch(() => {});
            },
        });

        watchRef.current = watch;

        return () => {
            watchRef.current = null;
            watch.stop();
        };
    }, [live, room.id, room.status]);

    return (
        <>
            <Head title={t('rooms.show.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {room.status === 'open'
                        ? t('rooms.show.lobby')
                        : t('rooms.show.title')}
                </h1>

                {state === null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-muted-foreground">
                            {t('rooms.show.left')}
                        </p>
                        <Button asChild variant="secondary">
                            <Link href={entrance()}>
                                {t('rooms.join.title')}
                            </Link>
                        </Button>
                    </div>
                )}

                {state !== null && state.room.code !== null && (
                    <section className="flex flex-col items-center gap-1 rounded-xl border bg-card p-4">
                        <span className="text-sm text-muted-foreground">
                            {t('rooms.show.code')}
                        </span>
                        <span className="text-4xl font-bold tracking-[0.3em] tabular-nums">
                            {state.room.code}
                        </span>
                    </section>
                )}

                {room.status === 'closed' && (
                    <p className="text-muted-foreground">
                        {t('rooms.show.closed')}
                    </p>
                )}

                {state?.room.locked && room.status === 'open' && (
                    <p className="text-sm text-muted-foreground">
                        {t('rooms.show.locked')}
                    </p>
                )}

                {state !== null && (
                    <section className="flex flex-col gap-2">
                        <h2 className="font-semibold">
                            {t('rooms.show.players')}
                        </h2>
                        <ul className="flex flex-col gap-2">
                            {state.players.map((player) => (
                                <li
                                    key={player.id}
                                    className="flex min-h-12 items-center gap-2 rounded-xl border bg-card px-4 py-2"
                                >
                                    <span className="min-w-0 flex-1 truncate">
                                        {player.name ??
                                            t('rooms.show.nameless')}
                                    </span>
                                    {player.id === state.me.player_id && (
                                        <Badge variant="secondary">
                                            {t('rooms.show.you')}
                                        </Badge>
                                    )}
                                    {player.host && (
                                        <Badge variant="outline">
                                            {t('rooms.show.host')}
                                        </Badge>
                                    )}
                                    {!player.present &&
                                        room.status === 'open' && (
                                            <Badge variant="outline">
                                                {t('rooms.show.away')}
                                            </Badge>
                                        )}
                                    {can.host &&
                                        room.status === 'open' &&
                                        !player.host && (
                                            <Form
                                                {...remove.form({
                                                    room: room.id,
                                                    member: player.id,
                                                })}
                                                options={{
                                                    preserveScroll: true,
                                                }}
                                            >
                                                <Button
                                                    type="submit"
                                                    size="sm"
                                                    variant="ghost"
                                                >
                                                    {t('rooms.show.remove')}
                                                </Button>
                                            </Form>
                                        )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div className="flex flex-wrap gap-2">
                    {can.host && room.status === 'open' && state !== null && (
                        <>
                            <Form
                                {...update.form(room.id)}
                                options={{ preserveScroll: true }}
                            >
                                <input
                                    type="hidden"
                                    name="locked"
                                    value={state.room.locked ? '0' : '1'}
                                />
                                <Button type="submit" variant="secondary">
                                    {state.room.locked
                                        ? t('rooms.show.unlock')
                                        : t('rooms.show.lock')}
                                </Button>
                            </Form>
                            <Form {...close.form(room.id)}>
                                <Button type="submit" variant="destructive">
                                    {t('rooms.show.close')}
                                </Button>
                            </Form>
                        </>
                    )}
                    {can.leave && (
                        <Form {...leave.form(room.id)}>
                            <Button type="submit" variant="outline">
                                {t('rooms.show.leave')}
                            </Button>
                        </Form>
                    )}
                    {can.forget && (
                        <Form {...forget.form(room.id)}>
                            <Button type="submit" variant="outline">
                                {t('rooms.show.forget')}
                            </Button>
                        </Form>
                    )}
                    {can.delete && (
                        <Form {...destroy.form(room.id)}>
                            <Button type="submit" variant="destructive">
                                {t('rooms.show.delete')}
                            </Button>
                        </Form>
                    )}
                </div>
            </div>
        </>
    );
}

RoomsShow.layout = {
    breadcrumbs: [{ title: 'nav.rooms', href: index() }],
};
