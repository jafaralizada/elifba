import { Form, Head, Link, router } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import { GameSetup } from '@/components/rooms/game-setup';
import type { GameSetupOptions } from '@/components/rooms/game-setup';
import { LiveRound } from '@/components/rooms/live-round';
import { Standing } from '@/components/rooms/standing';
import { useLiveRoom } from '@/components/rooms/use-live-room';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import {
    close,
    destroy,
    entrance,
    index,
    leave,
    screen,
    update,
} from '@/routes/rooms';
import { destroy as endGame } from '@/routes/rooms/game';
import { destroy as remove } from '@/routes/rooms/members';
import { destroy as forget } from '@/routes/rooms/name';
import type {
    RoomMechanic,
    RoomRankedPlayer,
    RoomState,
} from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';

/** A game of a closed room, with its ranking (`Rooms\Support\RoomResults`). */
export type RoomResultGame = {
    id: string;
    mechanic: RoomMechanic;
    difficulty: number;
    rounds: number;
    ranking: RoomRankedPlayer[];
};

type Props = {
    room: { id: string; status: 'open' | 'closed' };
    /** Null for a player who left an open room: no live state for them. */
    state: RoomState | null;
    /** The label of place n at n − 1, from the server. */
    place_labels: string[];
    /** What a new game may be; for the host of an open room only. */
    setup: GameSetupOptions | null;
    /** Where a phone joins the open room: the QR's address and the code field's. */
    join: { url: string; entry_url: string } | null;
    /** A closed room's games, each with its ranking; null while the room is open. */
    games: RoomResultGame[] | null;
    can: { host: boolean; leave: boolean; delete: boolean; forget: boolean };
};

/**
 * A room's page (architecture §12 "Rooms and joining", "The race"): while
 * the room is open, the lobby — the code and its QR, the players by name
 * with the viewer's own row marked, the host's lock, removal, close and
 * screen, and the host's setup of a game — then the game, round by round,
 * as the state says; once closed, the players, each game's ranking, the
 * room's standing and the ways out.
 *
 * The page follows the state with `useLiveRoom()`; it sends no client
 * event, and draws what the latest state says: it never derives a phase, a
 * solution or a point. Plain until B4's interface session designs it.
 */
export default function RoomsShow({
    room,
    state: initial,
    place_labels: placeLabels,
    setup,
    join,
    games,
    can,
}: Props) {
    const { t } = useTranslation();
    const { state, clock, refresh } = useLiveRoom({ room, initial });

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
                        {join !== null && (
                            <QRCodeSVG
                                value={join.url}
                                size={160}
                                level="M"
                                marginSize={2}
                                title={t('rooms.show.qr')}
                            />
                        )}
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
                        refresh={refresh}
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
                                .then(refresh);
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

                {state !== null && games !== null && games.length > 0 && (
                    <section className="flex flex-col gap-4">
                        <h2 className="font-semibold">
                            {t('rooms.show.games')}
                        </h2>
                        {games.map((played) => (
                            <div
                                key={played.id}
                                className="flex flex-col gap-2"
                            >
                                <h3 className="text-sm font-semibold">
                                    {t(
                                        `rooms.mechanics.${played.mechanic}.name`,
                                    )}
                                    {' · '}
                                    {t(`rooms.difficulty.${played.difficulty}`)}
                                    {' · '}
                                    {t('rooms.show.rounds', {
                                        count: played.rounds,
                                    })}
                                </h3>
                                <Standing
                                    ranking={played.ranking}
                                    players={state.players}
                                    me={state.me.player_id}
                                    placeLabels={placeLabels}
                                />
                            </div>
                        ))}
                    </section>
                )}

                {setup !== null && state !== null && !playing && (
                    <GameSetup
                        roomId={room.id}
                        options={setup}
                        onStarted={refresh}
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
                            <Button asChild variant="secondary">
                                <Link href={screen(room.id)}>
                                    {t('rooms.show.screen')}
                                </Link>
                            </Button>
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
