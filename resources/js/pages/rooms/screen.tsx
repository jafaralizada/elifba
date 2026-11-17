import { Head, Link, router } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import { MECHANIC_VIEWS } from '@/components/rooms/mechanics/views';
import { Standing } from '@/components/rooms/standing';
import { useLiveRoom } from '@/components/rooms/use-live-room';
import { secondsUntil, useServerNow } from '@/components/rooms/use-server-now';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index, show } from '@/routes/rooms';
import type { ServerClock } from '@/rooms/clock';
import type { RoomMechanic, RoomRound, RoomState } from '@/rooms/contract';

type Props = {
    room: { id: string; status: 'open' | 'closed' };
    state: RoomState;
    /** The label of place n at n − 1, from the server. */
    place_labels: string[];
    /** Where a phone joins: the QR's address and the code field's. */
    join: { url: string; entry_url: string } | null;
};

/**
 * The big screen (architecture §12 "The big screen"): the host's own
 * session on a laptop or a television's browser. While the room waits, its
 * code, the QR of `{APP_URL}/otaq/{code}` and the players; in a game, what
 * every player sees of the round — the countdown, the material while it is
 * studied, the question with the count of answers — then the reveal with
 * the round's results and the ranking; between games, the room's standing.
 * It follows the same state as the room page and writes nothing. Plain
 * until B4's interface session designs it.
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

    if (state === null || room.status === 'closed') {
        return (
            <>
                <Head title={t('rooms.screen.title')} />
                <div className="mx-auto flex w-full max-w-3xl flex-col items-start gap-4 p-6">
                    <p className="text-muted-foreground">
                        {t('rooms.show.closed')}
                    </p>
                    <Button asChild variant="secondary">
                        <Link href={show(room.id)}>
                            {t('rooms.screen.back')}
                        </Link>
                    </Button>
                </div>
            </>
        );
    }

    const game = state.game;
    const round = state.round;

    return (
        <>
            <Head title={t('rooms.screen.title')} />

            <div className="mx-auto flex w-full max-w-5xl flex-col gap-8 p-6">
                {game !== null && (
                    <p className="text-lg text-muted-foreground">
                        {t(`rooms.mechanics.${game.mechanic}.name`)}
                        {' · '}
                        {t(`rooms.difficulty.${game.difficulty}`)}
                    </p>
                )}

                {round === null && state.room.code !== null && (
                    <section className="flex flex-col items-center gap-4">
                        <span className="text-muted-foreground">
                            {join === null
                                ? t('rooms.show.code')
                                : t('rooms.screen.join', {
                                      address: join.entry_url,
                                  })}
                        </span>
                        <span className="text-7xl font-bold tracking-[0.3em] tabular-nums">
                            {state.room.code}
                        </span>
                        {join !== null && (
                            <QRCodeSVG
                                value={join.url}
                                size={256}
                                level="M"
                                marginSize={2}
                                title={t('rooms.show.qr')}
                            />
                        )}
                    </section>
                )}

                {round !== null && game !== null && (
                    <ScreenRound
                        state={state}
                        round={round}
                        mechanic={game.mechanic}
                        clock={clock}
                        placeLabels={placeLabels}
                    />
                )}

                {round === null && game?.phase === 'over' && (
                    <section className="flex flex-col gap-2">
                        <h2 className="font-semibold">
                            {t('rooms.game.standing')}
                        </h2>
                        <Standing
                            ranking={state.ranking}
                            players={state.players}
                            me={null}
                            placeLabels={placeLabels}
                        />
                    </section>
                )}

                {round === null && (
                    <section className="flex flex-col gap-2">
                        <h2 className="font-semibold">
                            {t('rooms.show.players')}
                        </h2>
                        <ul className="flex flex-wrap gap-2">
                            {state.players
                                .filter((player) => player.present)
                                .map((player) => (
                                    <li
                                        key={player.id}
                                        className="rounded-xl border bg-card px-4 py-2 text-lg"
                                    >
                                        {player.name ??
                                            t('rooms.show.nameless')}
                                    </li>
                                ))}
                        </ul>
                    </section>
                )}

                <Button asChild variant="ghost" className="self-start">
                    <Link href={show(room.id)}>{t('rooms.screen.back')}</Link>
                </Button>
            </div>
        </>
    );
}

/**
 * The current round as every player sees it, without anyone's own answer:
 * the seconds of the estimated server clock (for show only), the material
 * only while the state carries it and the clock is before the question,
 * the question with the count of answers, then the reveal.
 */
function ScreenRound({
    state,
    round,
    mechanic,
    clock,
    placeLabels,
}: {
    state: RoomState;
    round: RoomRound;
    mechanic: RoomMechanic;
    clock: ServerClock;
    placeLabels: string[];
}) {
    const { t } = useTranslation();
    const now = useServerNow(clock, true);
    const view = MECHANIC_VIEWS[mechanic];
    const names = new Map(
        state.players.map((player) => [player.id, player.name]),
    );
    const until =
        round.phase === 'countdown'
            ? round.opens_ms
            : round.phase === 'study'
              ? round.answer_from_ms
              : round.phase === 'answer'
                ? round.bell_ms
                : round.next_ms;

    return (
        <section className="flex flex-col gap-6">
            <div className="flex items-center justify-between text-lg text-muted-foreground">
                <span>
                    {t('rooms.game.round', {
                        index: round.index + 1,
                        total: round.total,
                    })}
                </span>
                {until !== null && (
                    <span className="text-3xl font-semibold tabular-nums">
                        {secondsUntil(until, now)}
                    </span>
                )}
            </div>

            {round.phase === 'countdown' && (
                <p className="py-10 text-center text-2xl">
                    {t('rooms.game.countdown')}
                </p>
            )}

            {round.phase === 'study' && (
                <div className="flex flex-col items-center gap-4">
                    <p className="text-xl font-medium">
                        {t(`rooms.mechanics.${mechanic}.study`)}
                    </p>
                    {round.study !== null &&
                        now !== null &&
                        now < round.answer_from_ms && (
                            <view.Study study={round.study} />
                        )}
                </div>
            )}

            {round.phase === 'answer' && round.ask !== null && (
                <div className="flex flex-col items-center gap-4">
                    <p className="text-xl font-medium">
                        {t(`rooms.mechanics.${mechanic}.ask`)}
                    </p>
                    <view.Ask
                        ask={round.ask}
                        picked={[]}
                        disabled
                        onPick={() => {}}
                    />
                    <p className="text-lg tabular-nums">
                        {t('rooms.game.answered_count', {
                            count: round.answered_count,
                            total: round.present_count,
                        })}
                    </p>
                </div>
            )}

            {round.phase === 'reveal' &&
                round.ask !== null &&
                round.reveal !== null && (
                    <div className="grid gap-6 md:grid-cols-2">
                        <view.Solution
                            ask={round.ask}
                            solution={round.reveal.solution}
                            mine={null}
                        />
                        <div className="flex flex-col gap-4">
                            <ul className="flex flex-col gap-1">
                                {round.reveal.results.map((result) => (
                                    <li
                                        key={result.player_id}
                                        className="flex items-center gap-2"
                                    >
                                        <span className="min-w-0 flex-1 truncate">
                                            {names.get(result.player_id) ??
                                                t('rooms.show.nameless')}
                                        </span>
                                        <span>
                                            {result.correct
                                                ? t('rooms.game.correct')
                                                : t('rooms.game.wrong')}
                                        </span>
                                        <span className="w-20 text-right tabular-nums">
                                            {t('rooms.game.points', {
                                                count: result.points,
                                            })}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            <Standing
                                ranking={state.ranking}
                                players={state.players}
                                me={null}
                                placeLabels={placeLabels}
                            />
                        </div>
                    </div>
                )}
        </section>
    );
}

RoomsScreen.layout = {
    breadcrumbs: [{ title: 'nav.rooms', href: index() }],
};
