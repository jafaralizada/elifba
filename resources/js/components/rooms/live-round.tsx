import { useEffect, useRef, useState } from 'react';
import type { ComponentType } from 'react';
import {
    RecallOrderAsk,
    RecallOrderSolution,
    RecallOrderStudy,
} from '@/components/rooms/mechanics/recall-order';
import { Standing } from '@/components/rooms/standing';
import { secondsUntil, useServerNow } from '@/components/rooms/use-server-now';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { store as answersRoute } from '@/routes/rooms/answers';
import { eventId, sendAnswer } from '@/rooms/answer';
import type { ServerClock } from '@/rooms/clock';
import type {
    RoomAnswer,
    RoomAsk,
    RoomMechanic,
    RoomMine,
    RoomRound,
    RoomState,
    RoomStudy,
} from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';

/** A mechanic's view: its study, its question and its reveal (§12 "Room games"). */
type MechanicView = {
    Study: ComponentType<{ study: RoomStudy }>;
    Ask: ComponentType<{
        ask: RoomAsk;
        picked: string[];
        disabled: boolean;
        onPick: (id: string) => void;
    }>;
    Solution: ComponentType<{
        ask: RoomAsk;
        solution: string[];
        mine: RoomMine | null;
    }>;
};

const VIEWS: Record<RoomMechanic, MechanicView> = {
    recall_order: {
        Study: RecallOrderStudy,
        Ask: RecallOrderAsk,
        Solution: RecallOrderSolution,
    },
};

const NOTES = {
    sending: 'rooms.game.sending',
    accepted: 'rooms.game.sent',
    duplicate: 'rooms.game.sent',
    answered: 'rooms.game.already',
    late: 'rooms.game.late',
    refused: 'rooms.game.refused',
    failed: 'rooms.game.failed',
} as const;

type Sent = {
    answer: RoomAnswer;
    status: keyof typeof NOTES;
};

/**
 * The current round of a live game as the state shows it (docs/
 * architecture.md §12 "The state", "An answer"): the countdown, the row to
 * study — only while the state carries it and the server's clock is
 * before the question — the question, then the reveal with the round's
 * results and the ranking. The page draws the phase the state says; the
 * seconds are the server's clock as estimated, for show only.
 *
 * An answer is the items tapped, sent once it holds what the question
 * asks, with one event id that every retry repeats. Its receipt says only
 * whether it was taken; right or wrong comes from the reveal.
 */
export function LiveRound({
    roomId,
    state,
    round,
    clock,
    placeLabels,
    refresh,
    onGone,
}: {
    roomId: string;
    state: RoomState;
    round: RoomRound;
    clock: ServerClock;
    placeLabels: string[];
    refresh: () => void;
    onGone: () => void;
}) {
    const { t, locale } = useTranslation();
    const now = useServerNow(clock, true);
    const view = VIEWS[state.game?.mechanic ?? 'recall_order'];
    const [draft, setDraft] = useState<{ round: string; ids: string[] }>({
        round: '',
        ids: [],
    });
    const [sent, setSent] = useState<Sent | null>(null);
    // What `keepTrying` reads while an answer is retried.
    const shownRound = useRef(round);

    useEffect(() => {
        shownRound.current = round;
    }, [round]);

    const picked = draft.round === round.id ? draft.ids : [];
    const mySent = sent?.answer.round_id === round.id ? sent : null;
    const plays = state.me.player_id !== null;
    const locked =
        !plays ||
        round.answered ||
        (mySent !== null &&
            mySent.status !== 'refused' &&
            mySent.status !== 'failed');

    const send = async (answer: RoomAnswer) => {
        setSent({ answer, status: 'sending' });

        const outcome = await sendAnswer({
            answer,
            post: (body) => roomRequest('POST', answersRoute.url(roomId), body),
            wait: (ms) =>
                new Promise((resolve) => window.setTimeout(resolve, ms)),
            keepTrying: () =>
                shownRound.current.id === answer.round_id &&
                shownRound.current.phase === 'answer',
        });

        switch (outcome.kind) {
            case 'receipt':
                setSent({ answer, status: outcome.receipt.status });
                refresh();
                break;
            case 'refused':
                setSent({ answer, status: 'refused' });
                setDraft({ round: answer.round_id, ids: [] });
                refresh();
                break;
            case 'gone':
                onGone();
                break;
            case 'failed':
                setSent({ answer, status: 'failed' });
                refresh();
                break;
        }
    };

    // What the page says of this round's answer: its own receipt, else the state.
    const note = mySent?.status ?? (round.answered ? 'accepted' : null);

    const pick = (id: string) => {
        if (locked || round.ask === null || picked.includes(id)) {
            return;
        }

        const ids = [...picked, id];
        setDraft({ round: round.id, ids });

        if (ids.length === round.ask.pick) {
            void send({
                event_id: eventId(clock.now() ?? 0),
                round_id: round.id,
                picked: ids,
            });
        }
    };

    const names = new Map(
        state.players.map((player) => [player.id, player.name]),
    );
    const seconds = (ms: number) =>
        new Intl.NumberFormat(locale, {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1,
        }).format(ms / 1000);

    return (
        <section className="flex flex-col gap-4 rounded-xl border p-4">
            <div className="flex items-center justify-between text-sm text-muted-foreground">
                <span>
                    {t('rooms.game.round', {
                        index: round.index + 1,
                        total: round.total,
                    })}
                </span>
                {round.phase === 'study' && (
                    <span className="tabular-nums">
                        {t('rooms.game.seconds', {
                            seconds: secondsUntil(round.answer_from_ms, now),
                        })}
                    </span>
                )}
                {round.phase === 'answer' && (
                    <span className="tabular-nums">
                        {t('rooms.game.seconds', {
                            seconds: secondsUntil(round.bell_ms, now),
                        })}
                    </span>
                )}
                {round.phase === 'reveal' && round.next_ms !== null && (
                    <span className="tabular-nums">
                        {t('rooms.game.next', {
                            seconds: secondsUntil(round.next_ms, now),
                        })}
                    </span>
                )}
            </div>

            {round.phase === 'countdown' && (
                <div className="flex flex-col items-center gap-2 py-6">
                    <span className="text-muted-foreground">
                        {t('rooms.game.countdown')}
                    </span>
                    <span className="text-6xl font-bold tabular-nums">
                        {secondsUntil(round.opens_ms, now)}
                    </span>
                </div>
            )}

            {round.phase === 'study' && (
                <div className="flex flex-col items-center gap-4 py-4">
                    <p className="font-medium">
                        {t(`rooms.mechanics.${state.game?.mechanic}.study`)}
                    </p>
                    {round.study !== null &&
                        now !== null &&
                        now < round.answer_from_ms && (
                            <view.Study study={round.study} />
                        )}
                </div>
            )}

            {round.phase === 'answer' && round.ask !== null && (
                <div className="flex flex-col items-center gap-4 py-2">
                    <p className="font-medium">
                        {t(`rooms.mechanics.${state.game?.mechanic}.ask`)}
                    </p>
                    <view.Ask
                        ask={round.ask}
                        picked={
                            round.answered && mySent !== null
                                ? mySent.answer.picked
                                : picked
                        }
                        disabled={locked}
                        onPick={pick}
                    />
                    {!locked && picked.length > 0 && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                setDraft({
                                    round: round.id,
                                    ids: picked.slice(0, -1),
                                })
                            }
                        >
                            {t('rooms.game.undo')}
                        </Button>
                    )}
                    {!plays && (
                        <p className="text-sm text-muted-foreground">
                            {t('rooms.game.watching')}
                        </p>
                    )}
                    {note !== null && note !== 'failed' && (
                        <p
                            className={
                                note === 'refused'
                                    ? 'text-sm text-destructive'
                                    : 'text-sm'
                            }
                        >
                            {t(NOTES[note])}
                        </p>
                    )}
                    {mySent?.status === 'failed' && (
                        <div className="flex items-center gap-2 text-sm">
                            <span className="text-destructive">
                                {t('rooms.game.failed')}
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="secondary"
                                onClick={() => void send(mySent.answer)}
                            >
                                {t('rooms.game.resend')}
                            </Button>
                        </div>
                    )}
                    <p className="text-sm text-muted-foreground tabular-nums">
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
                    <div className="flex flex-col gap-4">
                        <view.Solution
                            ask={round.ask}
                            solution={round.reveal.solution}
                            mine={round.reveal.mine}
                        />
                        {round.reveal.mine !== null && (
                            <p className="text-center font-semibold">
                                {round.reveal.mine.correct
                                    ? t('rooms.game.correct')
                                    : t('rooms.game.wrong')}
                                {' · '}
                                {t('rooms.game.points', {
                                    count: round.reveal.mine.points,
                                })}
                                {' · '}
                                {t('rooms.game.elapsed', {
                                    seconds: seconds(
                                        round.reveal.mine.elapsed_ms,
                                    ),
                                })}
                            </p>
                        )}
                        {round.reveal.mine === null && plays && (
                            <p className="text-center text-sm text-muted-foreground">
                                {t('rooms.game.no_answer')}
                            </p>
                        )}

                        <div className="flex flex-col gap-2">
                            <h3 className="text-sm font-semibold">
                                {t('rooms.game.results')}
                            </h3>
                            {round.reveal.results.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t('rooms.game.no_results')}
                                </p>
                            ) : (
                                <ul className="flex flex-col gap-1 text-sm">
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
                                            <span className="w-16 text-right tabular-nums">
                                                {t('rooms.game.points', {
                                                    count: result.points,
                                                })}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>

                        <div className="flex flex-col gap-2">
                            <h3 className="text-sm font-semibold">
                                {t('rooms.game.ranking')}
                            </h3>
                            <Standing
                                ranking={state.ranking}
                                players={state.players}
                                me={state.me.player_id}
                                placeLabels={placeLabels}
                            />
                        </div>
                    </div>
                )}
        </section>
    );
}
