import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, Maximize, Minimize, Trophy, Users } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { RoundHead } from '@/components/rooms/live-round';
import { MECHANIC_VIEWS } from '@/components/rooms/mechanics/views';
import { RoomQr, spacedCode, typedAddress } from '@/components/rooms/room-code';
import type { JoinLink } from '@/components/rooms/room-code';
import { Standing } from '@/components/rooms/standing';
import { useLiveRoom } from '@/components/rooms/use-live-room';
import { secondsUntil, useServerNow } from '@/components/rooms/use-server-now';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { show } from '@/routes/rooms';
import type { ServerClock } from '@/rooms/clock';
import type {
    RoomGame,
    RoomPlayer,
    RoomRound,
    RoomState,
} from '@/rooms/contract';

type Props = {
    room: { id: string; status: 'open' | 'closed' };
    state: RoomState;
    /** The label of place n at n − 1, from the server. */
    place_labels: string[];
    /** Where a phone joins: the QR's address and the code field's. */
    join: JoinLink | null;
};

/**
 * The big screen (docs/frontend.md rule 15, "The screen"; architecture §12
 * "The big screen"): the host's own session on a laptop or a television's
 * browser, without the app's shell. While the room waits, its QR, its
 * code and the players; in a game, what every player sees of the round —
 * the countdown, the material while it is studied, the question with the
 * count of answers — then the reveal with the ranking and each player's
 * result; after a game, the room's standing with the code for whoever
 * comes next. It follows the same state as the room page and writes
 * nothing: no control here changes the room.
 */
export default function RoomsScreen({
    room,
    state: initial,
    place_labels: placeLabels,
    join,
}: Props) {
    const { t } = useTranslation();
    // A closed room has no screen: its results are on the room's page.
    const { state, clock } = useLiveRoom({
        room,
        initial,
        onClosed: () => router.visit(show(room.id)),
    });
    const game = state?.game ?? null;
    const round = state?.round ?? null;
    const playing = game !== null && game.phase === 'live' && round !== null;

    return (
        <div className="flex min-h-dvh flex-col bg-background text-foreground">
            <Head title={t('rooms.screen.title')} />

            <header className="flex items-center gap-[1.5vw] border-b px-[3vw] py-[1.4vh]">
                <span className="flex size-[max(2.5rem,5vh)] shrink-0 items-center justify-center rounded-[22%] bg-green-700 text-white">
                    <AppLogoIcon className="size-[66%] fill-current" />
                </span>
                <span className="text-[max(1rem,2.6vh)] font-semibold">
                    {t('rooms.screen.title')}
                </span>
                {state?.room.code != null && playing && (
                    <span className="rounded-full border px-[1.2vh] py-[0.4vh] text-[max(0.875rem,2.2vh)] font-semibold tabular-nums">
                        {t('rooms.screen.code_short', {
                            code: spacedCode(state.room.code),
                        })}
                    </span>
                )}
                <span className="flex-1" />
                <FullscreenToggle />
                <Button asChild variant="ghost" className="h-11">
                    <Link href={show(room.id)}>
                        <ArrowLeft />
                        {t('rooms.screen.back')}
                    </Link>
                </Button>
            </header>

            <main className="flex flex-1 flex-col px-[4vw] py-[3vh]">
                {state === null || room.status === 'closed' ? (
                    <p className="text-[3vh] text-muted-foreground">
                        {t('rooms.show.closed')}
                    </p>
                ) : playing ? (
                    <ScreenRound
                        state={state}
                        game={game}
                        round={round}
                        clock={clock}
                        placeLabels={placeLabels}
                    />
                ) : game !== null && game.phase === 'over' ? (
                    <ScreenOver
                        state={state}
                        game={game}
                        join={join}
                        placeLabels={placeLabels}
                    />
                ) : (
                    <ScreenLobby state={state} join={join} />
                )}
            </main>
        </div>
    );
}

/** The players present, as chips. */
function PlayerChips({ players }: { players: RoomPlayer[] }) {
    const { t } = useTranslation();

    return (
        <ul className="flex flex-wrap gap-[1.2vh]">
            {players
                .filter((player) => player.present)
                .map((player) => (
                    <li
                        key={player.id}
                        className="max-w-[24vw] truncate rounded-full border bg-card px-[2vh] py-[0.9vh] text-[2.6vh]"
                    >
                        {player.name ?? t('rooms.show.nameless')}
                    </li>
                ))}
        </ul>
    );
}

/** While the room waits for its first game: the QR beside the code, how to join, and who is here. */
function ScreenLobby({
    state,
    join,
}: {
    state: RoomState;
    join: JoinLink | null;
}) {
    const { t } = useTranslation();
    const present = state.players.filter((player) => player.present).length;

    return (
        <div className="flex flex-1 flex-col justify-center gap-[5vh]">
            <div className="flex items-center justify-center gap-[5vw]">
                {join !== null && (
                    <RoomQr
                        url={join.url}
                        size={512}
                        fluid
                        className="w-[min(44vh,34vw)] p-[1.4vh]"
                    />
                )}
                <div className="flex min-w-0 flex-col gap-[2vh]">
                    <span className="text-[3vh] text-muted-foreground">
                        {t('rooms.show.code')}
                    </span>
                    {state.room.code !== null && (
                        <span className="text-[min(15vh,10vw)] leading-none font-bold tracking-wider whitespace-nowrap tabular-nums">
                            {spacedCode(state.room.code)}
                        </span>
                    )}
                    {join !== null && (
                        <span className="max-w-[40vw] text-[2.8vh] text-balance text-muted-foreground">
                            {t('rooms.screen.join', {
                                address: typedAddress(join.entry_url),
                            })}
                        </span>
                    )}
                </div>
            </div>

            <section className="flex flex-col gap-[1.6vh]">
                <h2 className="flex items-center gap-[1vh] text-[2.8vh] font-semibold">
                    <Users aria-hidden className="size-[3vh]" />
                    {t('rooms.show.players_count', { count: present })}
                </h2>
                <PlayerChips players={state.players} />
                <p className="text-[2.4vh] text-muted-foreground">
                    {t('rooms.screen.waiting')}
                </p>
            </section>
        </div>
    );
}

/** After a game: its end and the room's standing, with the code for whoever comes next. */
function ScreenOver({
    state,
    game,
    join,
    placeLabels,
}: {
    state: RoomState;
    game: RoomGame;
    join: JoinLink | null;
    placeLabels: string[];
}) {
    const { t } = useTranslation();

    return (
        <div className="grid flex-1 grid-cols-[minmax(0,1fr)_auto] gap-[4vw]">
            <section className="flex min-w-0 flex-col gap-[2.4vh]">
                <div className="flex items-center gap-[2vh]">
                    <span className="flex size-[8vh] items-center justify-center rounded-full bg-muted">
                        <Trophy aria-hidden className="size-[4.4vh]" />
                    </span>
                    <span className="flex flex-col">
                        <span className="text-[4.4vh] leading-tight font-bold">
                            {t('rooms.game.over')}
                        </span>
                        <span className="text-[2.4vh] text-muted-foreground">
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
                <h2 className="text-[2.8vh] font-semibold">
                    {t('rooms.game.standing')}
                </h2>
                <Standing
                    ranking={state.ranking}
                    players={state.players}
                    me={null}
                    placeLabels={placeLabels}
                    big
                />
            </section>

            {state.room.code !== null && (
                <aside className="flex w-[min(30vh,24vw)] flex-col items-center gap-[1.6vh] self-start rounded-2xl border bg-card p-[2vh] text-center">
                    {join !== null && (
                        <RoomQr
                            url={join.url}
                            size={256}
                            fluid
                            className="w-full p-[1vh]"
                        />
                    )}
                    <span className="text-[4.4vh] leading-none font-bold tracking-wider tabular-nums">
                        {spacedCode(state.room.code)}
                    </span>
                    <span className="text-[1.8vh] text-muted-foreground">
                        {t('rooms.screen.next_join')}
                    </span>
                </aside>
            )}
        </div>
    );
}

/**
 * The current round as every player sees it, without anyone's own answer:
 * the seconds and the bar of the estimated server clock (for show only),
 * the material only while the state carries it and the clock is before the
 * question, the question with the count of answers, then the reveal.
 */
function ScreenRound({
    state,
    game,
    round,
    clock,
    placeLabels,
}: {
    state: RoomState;
    game: RoomGame;
    round: RoomRound;
    clock: ServerClock;
    placeLabels: string[];
}) {
    const { t } = useTranslation();
    const now = useServerNow(clock, true);
    const view = MECHANIC_VIEWS[game.mechanic];

    return (
        <section
            data-phase={round.phase}
            data-round={round.index}
            className="flex flex-1 flex-col gap-[3vh]"
        >
            <RoundHead game={game} round={round} now={now} big />

            {round.phase === 'countdown' && (
                <div className="flex flex-1 flex-col items-center justify-center gap-[2vh] text-center">
                    <span className="text-[3.4vh] text-muted-foreground">
                        {t('rooms.game.countdown')}
                    </span>
                    <span className="text-[24vh] leading-none font-bold tabular-nums">
                        {secondsUntil(round.opens_ms, now)}
                    </span>
                    <p className="max-w-[60vw] text-[2.8vh] text-balance text-muted-foreground">
                        {t(`rooms.mechanics.${game.mechanic}.line`)}
                    </p>
                </div>
            )}

            {round.phase === 'study' && (
                <div className="flex flex-1 flex-col items-center justify-center gap-[4vh] pb-[8vh]">
                    <p className="text-[4vh] font-medium">
                        {t(`rooms.mechanics.${game.mechanic}.study`)}
                    </p>
                    {round.study !== null &&
                        now !== null &&
                        now < round.answer_from_ms && (
                            <view.Study study={round.study} big />
                        )}
                </div>
            )}

            {round.phase === 'answer' && round.ask !== null && (
                <div className="grid flex-1 grid-cols-[minmax(0,1fr)_auto] items-center gap-[4vw]">
                    <div className="flex flex-col items-center gap-[4vh]">
                        <p className="text-center text-[4vh] font-medium">
                            {t(`rooms.mechanics.${game.mechanic}.ask`)}
                        </p>
                        <view.Ask
                            ask={round.ask}
                            picked={[]}
                            disabled
                            onPick={() => {}}
                            big
                        />
                    </div>
                    <AnsweredCount
                        count={round.answered_count}
                        total={round.present_count}
                    />
                </div>
            )}

            {round.phase === 'reveal' &&
                round.ask !== null &&
                round.reveal !== null && (
                    <div className="grid flex-1 grid-cols-[minmax(0,5fr)_minmax(0,6fr)] items-start gap-[4vw]">
                        <view.Solution
                            ask={round.ask}
                            solution={round.reveal.solution}
                            mine={null}
                            big
                        />
                        <section className="flex min-w-0 flex-col gap-[1.6vh]">
                            <h2 className="text-[2.8vh] font-semibold">
                                {t('rooms.game.ranking')}
                            </h2>
                            <Standing
                                ranking={state.ranking}
                                players={state.players}
                                me={null}
                                placeLabels={placeLabels}
                                results={round.reveal.results}
                                big
                            />
                        </section>
                    </div>
                )}
        </section>
    );
}

/** The count of answers in large figures, with a bar. */
function AnsweredCount({ count, total }: { count: number; total: number }) {
    const { t } = useTranslation();

    return (
        <div className="flex w-[min(36vh,26vw)] flex-col items-center gap-[1.6vh] rounded-2xl border bg-card p-[3vh]">
            <span className="text-[2.6vh] text-muted-foreground">
                {t('rooms.screen.answered')}
            </span>
            <span className="text-[8vh] leading-none font-bold whitespace-nowrap tabular-nums">
                {t('rooms.screen.count', { count, total })}
            </span>
            <div
                aria-hidden
                className="h-[1.2vh] w-full overflow-hidden rounded-full bg-muted"
            >
                <div
                    className="h-full origin-left rounded-full bg-primary transition-transform duration-300 motion-reduce:transition-none"
                    style={{
                        transform: `scaleX(${total === 0 ? 0 : count / total})`,
                    }}
                />
            </div>
        </div>
    );
}

const noSubscription = () => () => {};

function subscribeFullscreen(change: () => void): () => void {
    document.addEventListener('fullscreenchange', change);

    return () => document.removeEventListener('fullscreenchange', change);
}

/** «Tam ekran», where the browser has it (never on an iPhone). */
function FullscreenToggle() {
    const { t } = useTranslation();
    const enabled = useSyncExternalStore(
        noSubscription,
        () => document.fullscreenEnabled,
        () => false,
    );
    const on = useSyncExternalStore(
        subscribeFullscreen,
        () => document.fullscreenElement !== null,
        () => false,
    );

    if (!enabled) {
        return null;
    }

    return (
        <Button
            type="button"
            variant="outline"
            className={cn('h-11')}
            aria-pressed={on}
            onClick={() => {
                void (
                    on
                        ? document.exitFullscreen()
                        : document.documentElement.requestFullscreen()
                ).catch(() => null);
            }}
        >
            {on ? <Minimize /> : <Maximize />}
            {t('rooms.screen.fullscreen')}
        </Button>
    );
}
