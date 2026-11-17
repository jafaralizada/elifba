import { Form, Head, Link, router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { GameSetup } from '@/components/rooms/game-setup';
import type { GameSetupOptions } from '@/components/rooms/game-setup';
import { LiveRound } from '@/components/rooms/live-round';
import { Standing } from '@/components/rooms/standing';
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
import { destroy as endGame } from '@/routes/rooms/game';
import { destroy as remove } from '@/routes/rooms/members';
import { destroy as forget } from '@/routes/rooms/name';
import { followRoom, serverClock } from '@/rooms/clock';
import type { RoomFollow } from '@/rooms/clock';
import type { RoomState } from '@/rooms/contract';
import { fetchState, roomRequest } from '@/rooms/http';
import type { RoomWatch } from '@/rooms/live';
import { watchRoom } from '@/rooms/live';
import { connectRoomEcho, roomChannel } from '@/rooms/socket';

type Props = {
    room: { id: string; status: 'open' | 'closed' };
    /** Null for a player who left an open room: no live state for them. */
    state: RoomState | null;
    /** The label of place n at n − 1, from the server. */
    place_labels: string[];
    /** What a new game may be; for the host of an open room only. */
    setup: GameSetupOptions | null;
    can: { host: boolean; leave: boolean; delete: boolean; forget: boolean };
};

/** Whether `next` may replace `shown`: a fetch that was overtaken never covers a newer state. */
function newer(next: RoomState, shown: RoomState | null): boolean {
    return (
        shown === null ||
        next.revision > shown.revision ||
        (next.revision === shown.revision && next.server_ms >= shown.server_ms)
    );
}

/**
 * A room's page (architecture §12 "Rooms and joining", "The race"): while
 * the room is open, the lobby — the code, the players by name with the
 * viewer's own row marked, the host's lock, removal and close, and the
 * host's setup of a game — then the game, round by round, as the state
 * says; once closed, the players, the room's standing and the ways out.
 *
 * The page reads the state when `room.changed` brings a higher revision,
 * at each moment of the timetable (`followRoom()`: the server's clock is
 * estimated from the states it fetches), and every 3 s without a socket in
 * the lobby and once it has answered. It sends no client event, and draws
 * what the latest state says: it never derives a phase, a solution or a
 * point. Plain until B4 designs it.
 */
export default function RoomsShow({
    room,
    state: initial,
    place_labels: placeLabels,
    setup,
    can,
}: Props) {
    const { t } = useTranslation();
    const [state, setState] = useState(initial);
    const [clock] = useState(() => serverClock());
    const live = room.status === 'open' && initial !== null;
    // One socket for the page's life, not one per reload of its props.
    const watchRef = useRef<RoomWatch | null>(null);
    const followRef = useRef<RoomFollow | null>(null);
    const refreshRef = useRef<() => void>(() => {});
    const firstRevision = useRef(initial?.revision ?? 0);
    // Two fetches may answer out of order: an older state never covers a newer one.
    const shown = useRef<RoomState | null>(initial);

    useEffect(() => {
        if (initial === null) {
            setState(null);

            return;
        }

        // The props carry no send time: read by the moment they are here.
        clock.observe(initial.server_ms, null, performance.now());

        if (newer(initial, shown.current) || shown.current === initial) {
            shown.current = initial;
            setState(initial);
            watchRef.current?.seen(initial.revision);
            followRef.current?.show(initial);
        }
    }, [initial, clock]);

    useEffect(() => {
        if (!live) {
            return;
        }

        let stopped = false;
        let echo: ReturnType<typeof connectRoomEcho> | null = null;

        try {
            echo = connectRoomEcho();
        } catch {
            echo = null;
        }

        const refresh = () => {
            void fetchState(stateRoute.url(room.id)).then((result) => {
                if (stopped) {
                    return;
                }

                if (result.kind === 'failed') {
                    follow.failed();

                    return;
                }

                if (result.kind === 'refused') {
                    // Removed, gone or closed: the page says which.
                    router.reload();

                    return;
                }

                const next = result.state;
                clock.observe(next.server_ms, result.sentAt, result.receivedAt);

                if (!newer(next, shown.current)) {
                    return;
                }

                shown.current = next;
                watch.seen(next.revision);

                if (next.room.status !== room.status) {
                    router.reload();
                } else {
                    setState(next);
                    follow.show(next);
                }
            });
        };

        const follow = followRoom({ clock, refresh });
        const watch = watchRoom({
            revision: firstRevision.current,
            channel: echo === null ? null : roomChannel(echo, room.id),
            refresh,
            onListening: follow.listening,
        });

        watchRef.current = watch;
        followRef.current = follow;
        refreshRef.current = refresh;

        if (shown.current !== null) {
            follow.show(shown.current);
        }

        // The props bound the server's clock from one side only: a fetch bounds it from both.
        refresh();

        return () => {
            stopped = true;
            watchRef.current = null;
            followRef.current = null;
            refreshRef.current = () => {};
            follow.stop();
            watch.stop();
        };
    }, [live, room.id, room.status, clock]);

    const game = state?.game ?? null;
    const round = state?.round ?? null;
    const playing = room.status === 'open' && game?.phase === 'live';

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

                {state !== null && game !== null && (
                    <p className="text-sm text-muted-foreground">
                        {t(`rooms.mechanics.${game.mechanic}.name`)}
                        {' · '}
                        {t(`rooms.difficulty.${game.difficulty}`)}
                    </p>
                )}

                {state !== null && playing && round !== null && (
                    <LiveRound
                        roomId={room.id}
                        state={state}
                        round={round}
                        clock={clock}
                        placeLabels={placeLabels}
                        refresh={() => refreshRef.current()}
                        onGone={() => router.reload()}
                    />
                )}

                {can.host && playing && (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            void roomRequest('DELETE', endGame.url(room.id))
                                .catch(() => null)
                                .then(() => refreshRef.current());
                        }}
                    >
                        {t('rooms.game.end')}
                    </Button>
                )}

                {state !== null && game?.phase === 'over' && (
                    <section className="flex flex-col gap-2">
                        {room.status === 'open' && (
                            <p className="font-semibold">
                                {t('rooms.game.over')}
                            </p>
                        )}
                        <h2 className="text-sm font-semibold">
                            {t('rooms.game.standing')}
                        </h2>
                        <Standing
                            ranking={state.ranking}
                            players={state.players}
                            me={state.me.player_id}
                            placeLabels={placeLabels}
                        />
                    </section>
                )}

                {setup !== null && state !== null && !playing && (
                    <GameSetup
                        roomId={room.id}
                        options={setup}
                        onStarted={() => refreshRef.current()}
                    />
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
