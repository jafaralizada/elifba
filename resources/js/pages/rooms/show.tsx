import { Form, Head, Link, router } from '@inertiajs/react';
import {
    ChevronDown,
    CircleStop,
    DoorClosed,
    Hourglass,
    Lock,
    LockOpen,
    LogOut,
    Monitor,
    Trash2,
    Trophy,
    UserMinus,
} from 'lucide-react';
import { useState } from 'react';
import { GameSetup } from '@/components/rooms/game-setup';
import type { GameSetupOptions } from '@/components/rooms/game-setup';
import { LiveRound } from '@/components/rooms/live-round';
import { RoomActionBar } from '@/components/rooms/room-action-bar';
import { CodeCard, CodeChip } from '@/components/rooms/room-code';
import type { JoinLink } from '@/components/rooms/room-code';
import { FinalStanding } from '@/components/rooms/final-standing';
import { RoomConfirm } from '@/components/rooms/room-confirm';
import { Standing } from '@/components/rooms/standing';
import { SoundToggle } from '@/components/rooms/sound-toggle';
import { useLiveRoom } from '@/components/rooms/use-live-room';
import { useRoomMotion } from '@/components/rooms/use-motion';
import type { RoomMotion } from '@/components/rooms/use-motion';
import { useRoomSound } from '@/components/rooms/use-room-sound';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
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
    RoomGame,
    RoomMechanic,
    RoomRankedPlayer,
    RoomState,
} from '@/rooms/contract';
import type { RoomSoundUrls } from '@/rooms/sound';
import { roomRequest } from '@/rooms/http';

/** A game of a closed room, with its ranking (`Rooms\Support\RoomResults`). */
export type RoomResultGame = {
    id: string;
    mechanic: RoomMechanic;
    difficulty: number;
    rounds: number;
    ranking: RoomRankedPlayer[];
};

type Can = { host: boolean; leave: boolean; delete: boolean; forget: boolean };

type Props = {
    room: { id: string; status: 'open' | 'closed' };
    /** Null for a player who left an open room: no live state for them. */
    state: RoomState | null;
    /** The label of place n at n − 1, from the server. */
    place_labels: string[];
    /** What the room's choice may be; for the host of an open room only (the choice itself is the state's `setup`). */
    catalog: GameSetupOptions | null;
    /** Where a phone joins the open room: the QR's address and the code field's. */
    join: JoinLink | null;
    /** A closed room's games, each with its ranking; null while the room is open. */
    games: RoomResultGame[] | null;
    /** This phone's own quiet cues by name (ADR 025); null when it follows no open room. */
    sounds: RoomSoundUrls | null;
    can: Can;
};

/**
 * A room's page (docs/frontend.md rule 15, "The room page"; architecture
 * §12 "Rooms and joining", "The race"). While the room is open: the lobby
 * — the code and its QR, the players, the host's tools, and after a game
 * its end and the room's standing — with the host's «Yeni oyun» or a
 * player's waiting line at the bottom; during a game the round, phase by
 * phase, with the code as a chip in the header. Once closed, its results.
 *
 * The page follows the state with `useLiveRoom()`; it sends no client
 * event, and draws what the latest state says: it never derives a phase,
 * a solution or a point. A moment moves only when the page saw it begin
 * (`useRoomMotion()`, ADR 025): the one it opened on stands still. Its
 * sound is the viewer's own and quiet (`useRoomSound()`): a tap, the
 * answer sent — one sound for every answer — and, from the reveal, the
 * verdict; the room's moments are the screen's. The first tap unlocks it,
 * and the round's head holds its mute.
 */
export default function RoomsShow({
    room,
    state: initial,
    place_labels: placeLabels,
    catalog,
    join,
    games,
    sounds,
    can,
}: Props) {
    const { t } = useTranslation();
    const { state, clock, refresh } = useLiveRoom({ room, initial });
    const motion = useRoomMotion(state);
    const sound = useRoomSound({ speaker: 'phone', sounds, state, clock });

    if (room.status === 'closed') {
        return (
            <ClosedRoom
                room={room}
                state={state}
                games={games ?? []}
                placeLabels={placeLabels}
                can={can}
            />
        );
    }

    if (state === null) {
        return (
            <div className="mx-auto flex w-full max-w-xl flex-col gap-4 px-4 pt-4 pb-8 md:px-6">
                <Head title={t('rooms.show.title')} />
                <h1 className="text-2xl font-semibold tracking-tight">
                    {t('rooms.show.title')}
                </h1>
                <p className="text-muted-foreground">{t('rooms.show.left')}</p>
                <Button asChild className="h-13 text-base">
                    <Link href={entrance()}>{t('rooms.join.title')}</Link>
                </Button>
            </div>
        );
    }

    const game = state.game;
    const round = state.round;
    const playing = game !== null && game.phase === 'live' && round !== null;

    return (
        <div className="flex flex-1 flex-col">
            <Head title={t('rooms.show.title')} />

            <div className="mx-auto flex w-full max-w-xl flex-col gap-5 px-4 pt-4 pb-6 md:px-6">
                {playing ? (
                    <h1 className="sr-only">{t('rooms.show.title')}</h1>
                ) : (
                    <header className="flex min-h-11 items-center justify-between gap-3">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('rooms.show.title')}
                        </h1>
                        {state.room.locked && (
                            <Badge variant="outline" className="h-7 gap-1">
                                <Lock aria-hidden />
                                {t('rooms.show.locked_badge')}
                            </Badge>
                        )}
                    </header>
                )}

                {playing ? (
                    <>
                        <LiveRound
                            roomId={room.id}
                            state={state}
                            game={game}
                            round={round}
                            clock={clock}
                            placeLabels={placeLabels}
                            refresh={refresh}
                            onGone={() => router.reload()}
                            motion={motion}
                            sound={sound}
                            aside={
                                <>
                                    {sounds !== null && (
                                        <SoundToggle sound={sound} />
                                    )}
                                    {state.room.code !== null && (
                                        <CodeChip
                                            code={state.room.code}
                                            join={join}
                                        />
                                    )}
                                </>
                            }
                        />
                        {can.host && (
                            <div className="flex justify-center border-t pt-4">
                                <RoomConfirm
                                    trigger={
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            className="h-11 text-muted-foreground"
                                        >
                                            <CircleStop />
                                            {t('rooms.game.end')}
                                        </Button>
                                    }
                                    title={t('rooms.game.end_title')}
                                    description={t(
                                        'rooms.game.end_description',
                                    )}
                                    confirmLabel={t('rooms.game.end')}
                                    destructive
                                    onConfirm={() =>
                                        roomRequest(
                                            'DELETE',
                                            endGame.url(room.id),
                                        ).finally(refresh)
                                    }
                                />
                            </div>
                        )}
                    </>
                ) : (
                    <Lobby
                        room={room}
                        state={state}
                        game={game}
                        join={join}
                        placeLabels={placeLabels}
                        can={can}
                        motion={motion}
                    />
                )}
            </div>

            {!playing && (
                <RoomActionBar>
                    {catalog !== null && state.setup !== null ? (
                        <GameSetup
                            roomId={room.id}
                            options={catalog}
                            choice={state.setup}
                            onChanged={refresh}
                        />
                    ) : (
                        <p className="flex min-h-11 items-center justify-center gap-2 text-center text-sm text-muted-foreground">
                            <Hourglass
                                aria-hidden
                                className="size-4 shrink-0"
                            />
                            {t('rooms.show.waiting')}
                        </p>
                    )}
                </RoomActionBar>
            )}
        </div>
    );
}

/**
 * The lobby, before the first game and between games: a game's end with
 * its final (the room's standing, ADR 025), the code and its QR, the
 * players, the host's tools and a player's way out.
 */
function Lobby({
    room,
    state,
    game,
    join,
    placeLabels,
    can,
    motion,
}: {
    room: Props['room'];
    state: RoomState;
    game: RoomGame | null;
    join: JoinLink | null;
    placeLabels: string[];
    can: Can;
    motion: RoomMotion;
}) {
    const { t } = useTranslation();
    const present = state.players.filter((player) => player.present).length;

    return (
        <>
            {game !== null && game.phase === 'over' && (
                <section
                    key={game.id}
                    data-motion={motion.phase ? 'on' : undefined}
                    className="flex flex-col gap-3"
                >
                    <div className="flex items-center gap-3 rounded-2xl border bg-card p-4 room-motion:animate-room-enter">
                        <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-amber-400 text-amber-950 room-motion:animate-room-pop">
                            <Trophy aria-hidden className="size-5" />
                        </span>
                        <span className="flex min-w-0 flex-col">
                            <span className="font-semibold">
                                {t('rooms.game.over')}
                            </span>
                            <span className="truncate text-sm text-muted-foreground">
                                {t('rooms.setup.summary', {
                                    game: t(
                                        `rooms.mechanics.${game.mechanic}.name`,
                                    ),
                                    difficulty: t(
                                        `rooms.difficulty.${game.difficulty}`,
                                    ),
                                    count: game.rounds,
                                })}
                            </span>
                        </span>
                    </div>
                    <h2 className="text-sm font-semibold">
                        {t('rooms.game.standing')}
                    </h2>
                    <FinalStanding
                        ranking={state.ranking}
                        players={state.players}
                        me={state.me.player_id}
                        placeLabels={placeLabels}
                        animate={motion.phase}
                    />
                </section>
            )}

            {state.room.code !== null && (
                <CodeCard code={state.room.code} join={join} />
            )}

            {state.room.locked && (
                <p className="-mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                    <Lock aria-hidden className="size-4 shrink-0" />
                    {t('rooms.show.locked')}
                </p>
            )}

            <section className="flex flex-col gap-2">
                <h2 className="text-sm font-semibold">
                    {t('rooms.show.players_count', { count: present })}
                </h2>
                <ul className="flex flex-col divide-y overflow-hidden rounded-xl border bg-card">
                    {state.players.map((player) => {
                        const name = player.name ?? t('rooms.show.nameless');

                        return (
                            <li
                                key={player.id}
                                className={cn(
                                    'flex min-h-12 items-center gap-2 py-1 pr-1 pl-3',
                                    player.id === state.me.player_id &&
                                        'bg-accent',
                                )}
                            >
                                <span
                                    className={cn(
                                        'min-w-0 flex-1 truncate',
                                        !player.present &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    {name}
                                </span>
                                {player.id === state.me.player_id && (
                                    <Badge
                                        variant="secondary"
                                        className="bg-background"
                                    >
                                        {t('rooms.show.you')}
                                    </Badge>
                                )}
                                {player.host && (
                                    <Badge variant="outline">
                                        {t('rooms.show.host')}
                                    </Badge>
                                )}
                                {!player.present && (
                                    <Badge variant="outline">
                                        {t('rooms.show.away')}
                                    </Badge>
                                )}
                                {can.host && !player.host && (
                                    <RoomConfirm
                                        trigger={
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                className="h-11 shrink-0 px-3 text-muted-foreground"
                                            >
                                                <UserMinus />
                                                {t('rooms.show.remove')}
                                            </Button>
                                        }
                                        title={t('rooms.show.remove_title', {
                                            name,
                                        })}
                                        description={t(
                                            'rooms.show.remove_description',
                                        )}
                                        confirmLabel={t('rooms.show.remove')}
                                        destructive
                                        form={remove.form({
                                            room: room.id,
                                            member: player.id,
                                        })}
                                    />
                                )}
                            </li>
                        );
                    })}
                </ul>
            </section>

            {can.host && (
                <section className="flex flex-col gap-2">
                    <h2 className="text-sm font-semibold">
                        {t('rooms.show.tools')}
                    </h2>
                    <div className="grid grid-cols-2 gap-2">
                        <Form
                            {...update.form(room.id)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="locked"
                                        value={state.room.locked ? '0' : '1'}
                                    />
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        className="h-11 w-full"
                                        disabled={processing}
                                    >
                                        {state.room.locked ? (
                                            <LockOpen />
                                        ) : (
                                            <Lock />
                                        )}
                                        {state.room.locked
                                            ? t('rooms.show.unlock')
                                            : t('rooms.show.lock')}
                                    </Button>
                                </>
                            )}
                        </Form>
                        <Button asChild variant="outline" className="h-11">
                            <Link href={screen(room.id)}>
                                <Monitor />
                                {t('rooms.show.screen')}
                            </Link>
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {t('rooms.show.screen_hint')}
                    </p>
                    <RoomConfirm
                        trigger={
                            <Button
                                type="button"
                                variant="outline"
                                className="h-11 border-destructive/40 text-red-600 hover:text-red-600 dark:text-red-400 dark:hover:text-red-400"
                            >
                                <DoorClosed />
                                {t('rooms.show.close')}
                            </Button>
                        }
                        title={t('rooms.show.close_title')}
                        description={t('rooms.show.close_description')}
                        confirmLabel={t('rooms.show.close')}
                        destructive
                        form={close.form(room.id)}
                    />
                </section>
            )}

            {can.leave && (
                <Form {...leave.form(room.id)} className="flex justify-center">
                    {({ processing }) => (
                        <Button
                            type="submit"
                            variant="ghost"
                            className="h-11 text-muted-foreground"
                            disabled={processing}
                        >
                            <LogOut />
                            {t('rooms.show.leave')}
                        </Button>
                    )}
                </Form>
            )}
        </>
    );
}

/**
 * A closed room: its results (docs/frontend.md rule 15, "A closed room") —
 * the room's standing, each game with its ranking, and the ways out:
 * «Adımı sil» for a player, «Otağı sil» for the host. Names are read from
 * the accounts as they are now; a name taken out reads «Oyunçu».
 */
function ClosedRoom({
    room,
    state,
    games,
    placeLabels,
    can,
}: {
    room: Props['room'];
    state: RoomState | null;
    games: RoomResultGame[];
    placeLabels: string[];
    can: Can;
}) {
    const { t } = useTranslation();

    return (
        <div className="mx-auto flex w-full max-w-xl flex-col gap-6 px-4 pt-4 pb-10 md:px-6">
            <Head title={t('rooms.show.title')} />
            <header className="flex flex-col gap-1">
                <div className="flex min-h-11 items-center gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('rooms.show.title')}
                    </h1>
                    <Badge variant="secondary" className="h-7">
                        {t('rooms.show.closed_badge')}
                    </Badge>
                </div>
                <p className="text-sm text-muted-foreground">
                    {t('rooms.show.closed')}
                </p>
            </header>

            {state !== null && (
                <section className="flex flex-col gap-2">
                    <h2 className="font-semibold">
                        {t('rooms.game.standing')}
                    </h2>
                    {games.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            {t('rooms.show.no_games')}
                        </p>
                    ) : (
                        <Standing
                            ranking={state.ranking}
                            players={state.players}
                            me={state.me.player_id}
                            placeLabels={placeLabels}
                        />
                    )}
                </section>
            )}

            {state !== null && games.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h2 className="font-semibold">
                        {t('rooms.show.games_count', { count: games.length })}
                    </h2>
                    {games.map((played, position) => (
                        <GameResult
                            key={played.id}
                            game={played}
                            state={state}
                            placeLabels={placeLabels}
                            defaultOpen={position === 0}
                        />
                    ))}
                </section>
            )}

            {(can.forget || can.delete) && (
                <section className="flex flex-col gap-5 border-t pt-6">
                    {can.forget && (
                        <div className="flex flex-col gap-2">
                            <p className="text-sm text-muted-foreground">
                                {t('rooms.show.forget_line')}
                            </p>
                            <RoomConfirm
                                trigger={
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-11"
                                    >
                                        <UserMinus />
                                        {t('rooms.show.forget')}
                                    </Button>
                                }
                                title={t('rooms.show.forget_title')}
                                description={t('rooms.show.forget_line')}
                                confirmLabel={t('rooms.show.forget')}
                                destructive
                                form={forget.form(room.id)}
                            />
                        </div>
                    )}
                    {can.delete && (
                        <div className="flex flex-col gap-2">
                            <p className="text-sm text-muted-foreground">
                                {t('rooms.show.delete_line')}
                            </p>
                            <RoomConfirm
                                trigger={
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        className="h-11"
                                    >
                                        <Trash2 />
                                        {t('rooms.show.delete')}
                                    </Button>
                                }
                                title={t('rooms.show.delete_title')}
                                description={t('rooms.show.delete_line')}
                                confirmLabel={t('rooms.show.delete')}
                                destructive
                                form={destroy.form(room.id)}
                            />
                        </div>
                    )}
                </section>
            )}
        </div>
    );
}

/** One game of a closed room: its name, difficulty and rounds over its ranking, which opens and closes. */
function GameResult({
    game,
    state,
    placeLabels,
    defaultOpen,
}: {
    game: RoomResultGame;
    state: RoomState;
    placeLabels: string[];
    defaultOpen: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(defaultOpen);
    const panel = `game-${game.id}`;

    return (
        <div className="flex flex-col gap-2">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={panel}
                onClick={() => setOpen(!open)}
                className="flex min-h-12 items-center gap-3 rounded-xl border bg-card px-3 py-2 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="truncate font-medium">
                        {t(`rooms.mechanics.${game.mechanic}.name`)}
                    </span>
                    <span className="text-sm text-muted-foreground">
                        {t(`rooms.difficulty.${game.difficulty}`)}
                        {' · '}
                        {t('rooms.show.rounds', { count: game.rounds })}
                    </span>
                </span>
                <ChevronDown
                    aria-hidden
                    className={cn(
                        'size-5 shrink-0 text-muted-foreground transition-transform motion-reduce:transition-none',
                        open && 'rotate-180',
                    )}
                />
            </button>
            <div id={panel} hidden={!open}>
                <Standing
                    ranking={game.ranking}
                    players={state.players}
                    me={state.me.player_id}
                    placeLabels={placeLabels}
                />
            </div>
        </div>
    );
}

RoomsShow.layout = {
    breadcrumbs: [{ title: 'nav.rooms', href: index() }],
};
