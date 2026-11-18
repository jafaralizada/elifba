import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomState as State } from '@/rooms/contract';
import type { RoomSpeaker } from '@/rooms/sound';
import { finalTimeline } from './final';
import { REVEAL_MS } from './motion';
import { roomCues, sameMoment, STALE_MS } from './sound-cues';
import type { RoomCue, RoomEar } from './sound-cues';
import { standingGroups } from './standing-groups';

/*
 * When a room game sounds (ADR 025 "Sound", docs/frontend.md rule 15
 * "Sound"): a cue plays when its moment begins and the page saw it begin,
 * once however often the state comes again, never for the moment a page
 * opened on; the screen plays the room's moments and the same whatever
 * anyone answered; a phone plays its own answer sent, one sound for every
 * answer, and its verdict only from the reveal.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../../tests/Fixtures/rooms/state.*.json',
    { eager: true, import: 'default' },
);

function state(name: string): State {
    return RoomState.parse(
        fixtures[`../../../../tests/Fixtures/rooms/state.${name}.json`],
    );
}

type LiveState = Extract<State, { round: object }>;

function live(name: string): LiveState {
    const found = state(name);

    if (found.round === null || found.game === null) {
        throw new Error(`${name} has no round`);
    }

    return found as LiveState;
}

const lobby = state('lobby');
const countdown = live('countdown');
const study = live('study');
const answered = live('answer');
const reveal = live('reveal');
const over = state('over');

/** The question before the viewer answered. */
const answer = {
    ...answered,
    round: { ...answered.round, answered: false, answered_count: 0 },
} as State;

/** The same reveal with the viewer's answer wrong, or without an answer. */
function revealWith(mine: 'right' | 'wrong' | 'none'): State {
    const base = reveal.round;

    if (base.phase !== 'reveal') {
        throw new Error('a reveal is needed');
    }

    return {
        ...reveal,
        round: {
            ...base,
            reveal: {
                ...base.reveal,
                mine:
                    mine === 'none' || base.reveal.mine === null
                        ? null
                        : {
                              ...base.reveal.mine,
                              correct: mine === 'right',
                              points: mine === 'right' ? 19 : 0,
                          },
            },
        },
    } as State;
}

const opens = countdown.round.opens_ms;
const answerFrom = answered.round.answer_from_ms;
const bell = answered.round.bell_ms;
const closes = reveal.round.closes_ms ?? 0;

/** Each step of one round as a page hears it: the state and the server's clock then. */
function game(mine: 'right' | 'wrong' | 'none'): RoomEar[] {
    const shown = revealWith(mine);
    // Without an answer the viewer's question stays unanswered.
    const sent = mine === 'none' ? answer : answered;

    return [
        { state: lobby, now: opens - 5000 },
        { state: countdown, now: opens - 2500 },
        { state: countdown, now: opens - 2300 },
        { state: countdown, now: opens - 1500 },
        { state: countdown, now: opens - 500 },
        { state: countdown, now: opens },
        { state: study, now: study.round.opens_ms + 80 },
        { state: study, now: study.round.opens_ms + 900 },
        { state: answer, now: answerFrom + 60 },
        { state: answer, now: answerFrom + 1200 },
        { state: sent, now: answerFrom + 2400 },
        { state: sent, now: answerFrom + 3000 },
        { state: sent, now: bell - 2900 },
        { state: sent, now: bell - 2500 },
        { state: sent, now: bell - 1900 },
        { state: sent, now: bell - 900 },
        { state: sent, now: bell - 100 },
        { state: shown, now: closes + 120 },
        { state: shown, now: closes + 600 },
        { state: shown, now: closes + 3000 },
        { state: over, now: over.server_ms + 50 },
        { state: over, now: over.server_ms + 3000 },
    ];
}

/** Every cue a page plays along `ears`, with the step it plays at. */
function heard(
    ears: RoomEar[],
    speaker: RoomSpeaker,
): { step: number; cue: RoomCue }[] {
    const played: { step: number; cue: RoomCue }[] = [];

    ears.forEach((ear, step) => {
        const previous = step === 0 ? null : ears[step - 1];

        for (const cue of roomCues(previous, ear, speaker)) {
            played.push({ step, cue });
        }
    });

    return played;
}

const names = (played: { cue: RoomCue }[]) =>
    played.map(({ cue }) => cue.sound);

const winnerAt =
    finalTimeline(standingGroups(over.ranking, []), 'screen').winnerAt ?? -1;

describe('roomCues on the screen', () => {
    it("plays each of the room's moments once, however often the state comes again", () => {
        const played = heard(game('right'), 'screen');

        expect(played.map(({ step, cue }) => [step, cue])).toEqual([
            [1, { sound: 'count', at: 0 }],
            [3, { sound: 'count', at: 0 }],
            [4, { sound: 'count', at: 0 }],
            [6, { sound: 'study', at: 0 }],
            [8, { sound: 'question', at: 0 }],
            [12, { sound: 'tick', at: 0 }],
            [14, { sound: 'tick', at: 0 }],
            [15, { sound: 'tick', at: 0 }],
            [17, { sound: 'close', at: 0 }],
            [17, { sound: 'ranking', at: REVEAL_MS.ranking }],
            [20, { sound: 'roll', at: winnerAt, ends: true }],
            [20, { sound: 'fanfare', at: winnerAt }],
        ]);
    });

    it('plays the same whatever anyone answered: it has no viewer', () => {
        const right = heard(game('right'), 'screen');

        expect(heard(game('wrong'), 'screen')).toEqual(right);
        expect(heard(game('none'), 'screen')).toEqual(right);
    });

    it('rolls only towards a first place, and plays nothing for a final nobody scored in', () => {
        const nobody = {
            ...over,
            ranking: over.ranking.map((row) => ({
                ...row,
                points: 0,
                place: null,
            })),
        } as State;

        expect(
            roomCues(
                { state: reveal, now: closes + 100 },
                { state: nobody, now: closes + 5100 },
                'screen',
            ),
        ).toEqual([]);
        expect(winnerAt).toBeGreaterThan(0);
    });

    it('ticks only in the last three seconds, once a second', () => {
        const ticks = heard(game('right'), 'screen').filter(
            ({ cue }) => cue.sound === 'tick',
        );

        expect(ticks.map(({ step }) => game('right')[step].now)).toEqual([
            bell - 2900,
            bell - 1900,
            bell - 900,
        ]);
    });
});

describe('roomCues on a phone', () => {
    it('plays the answer sent once, and the verdict only from the reveal', () => {
        expect(
            heard(game('right'), 'phone').map(({ step, cue }) => [step, cue]),
        ).toEqual([
            [10, { sound: 'sent', at: 0 }],
            [17, { sound: 'right', at: 0 }],
        ]);
        expect(names(heard(game('wrong'), 'phone'))).toEqual(['sent', 'wrong']);
    });

    it("plays none of the room's moments", () => {
        const played = names(heard(game('right'), 'phone'));

        for (const room of [
            'count',
            'study',
            'question',
            'tick',
            'close',
            'ranking',
            'roll',
            'fanfare',
        ]) {
            expect(played).not.toContain(room);
        }
    });

    it('plays nothing at a reveal without an answer, or for a host who does not play', () => {
        expect(names(heard(game('none'), 'phone'))).toEqual([]);

        const watching = (ear: RoomEar): RoomEar => ({
            ...ear,
            state:
                ear.state === null
                    ? null
                    : ({
                          ...ear.state,
                          me: { player_id: null, host: true },
                      } as State),
        });

        expect(
            roomCues(
                watching({ state: answered, now: bell - 100 }),
                watching({ state: revealWith('right'), now: closes + 100 }),
                'phone',
            ),
        ).toEqual([]);
    });

    it('plays the same before the reveal for a right and a wrong answer', () => {
        const before = (mine: 'right' | 'wrong') =>
            heard(game(mine), 'phone').filter(({ step }) => step < 17);

        expect(before('wrong')).toEqual(before('right'));
    });
});

describe('roomCues and the moment a page opened on', () => {
    it('plays nothing for the first thing a page hears', () => {
        for (const speaker of ['screen', 'phone'] as const) {
            for (const ear of [...game('right'), ...game('wrong')]) {
                expect(roomCues(null, ear, speaker)).toEqual([]);
            }
        }
    });

    it('replays nothing after a reload mid-phase, and plays what begins after it', () => {
        for (const speaker of ['screen', 'phone'] as const) {
            const steps = game('right');

            // Opened at each step: the moment it opened on stays silent, however often it comes.
            steps.forEach((ear, step) => {
                const again = { ...ear, now: (ear.now ?? 0) + 50 };

                expect(
                    heard([ear, again, again], speaker),
                    `${speaker} ${step}`,
                ).toEqual([]);
            });
        }

        // Opened in the countdown's second second: the next second is new, not a replay.
        expect(
            names(
                heard(
                    [
                        { state: countdown, now: opens - 1500 },
                        { state: countdown, now: opens - 1400 },
                        { state: countdown, now: opens - 500 },
                    ],
                    'screen',
                ),
            ),
        ).toEqual(['count']);

        // Opened on the final: no roll and no fanfare.
        expect(
            heard(
                [
                    { state: over, now: 1 },
                    { state: over, now: 2 },
                ],
                'screen',
            ),
        ).toEqual([]);

        // Opened after the answer was sent: no «sent» again.
        expect(
            heard(
                [
                    { state: answered, now: answerFrom + 3000 },
                    { state: answered, now: answerFrom + 6000 },
                ],
                'phone',
            ),
        ).toEqual([]);
    });

    it('announces no moment heard long after it began', () => {
        expect(
            roomCues(
                { state: answered, now: bell - 100 },
                { state: reveal, now: closes + STALE_MS + 1 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: answered, now: bell - 100 },
                { state: reveal, now: closes + STALE_MS + 1 },
                'phone',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: answered, now: bell - 100 },
                { state: reveal, now: closes + STALE_MS },
                'phone',
            ),
        ).toEqual([{ sound: 'right', at: 0 }]);
    });
});

describe('roomCues before the reveal', () => {
    /** The state with its round watched: every property a cue reads of it is recorded. */
    function watched(source: State, reads: Set<string>): State {
        if (source.round === null) {
            return source;
        }

        const round = new Proxy(source.round, {
            get: (target, property, receiver) => {
                reads.add(String(property));

                return Reflect.get(target, property, receiver);
            },
        });

        return { ...source, round } as State;
    }

    it("reads no verdict, no solution and no one's result before the reveal", () => {
        for (const speaker of ['screen', 'phone'] as const) {
            const reads = new Set<string>();
            const ears = game('right')
                .filter(({ state }) => state?.round?.phase !== 'reveal')
                .map((ear) => ({
                    ...ear,
                    state:
                        ear.state === null ? null : watched(ear.state, reads),
                }));

            heard(ears, speaker);

            expect(reads.has('phase'), speaker).toBe(true);
            expect(reads.has('reveal'), speaker).toBe(false);
        }
    });

    it("never reads the viewer's verdict on the screen, even in the reveal", () => {
        const base = revealWith('right');

        if (base.round?.phase !== 'reveal') {
            throw new Error('a reveal is needed');
        }

        const secret = {
            ...base,
            round: {
                ...base.round,
                reveal: {
                    ...base.round.reveal,
                    get mine(): never {
                        throw new Error('the screen read mine');
                    },
                },
            },
        } as State;

        expect(
            names(
                heard(
                    [
                        { state: answered, now: bell - 100 },
                        { state: secret, now: closes + 100 },
                    ],
                    'screen',
                ),
            ),
        ).toEqual(['close', 'ranking']);
    });
});

describe('sameMoment', () => {
    it('is the game, the round and the phase: a beat or a fetch again is the same moment', () => {
        expect(
            sameMoment(
                { state: countdown, now: opens - 2500 },
                { state: countdown, now: opens - 500 },
                'screen',
            ),
        ).toBe(true);
        expect(
            sameMoment(
                { state: answer, now: answerFrom },
                { state: answered, now: bell - 100 },
                'phone',
            ),
        ).toBe(true);
        expect(
            sameMoment(
                { state: answered, now: bell - 100 },
                { state: reveal, now: closes + 100 },
                'screen',
            ),
        ).toBe(false);
    });
});
