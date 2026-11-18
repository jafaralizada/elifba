import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomState as State } from '@/rooms/contract';
import type { RoomSpeaker } from '@/rooms/sound';
import { finalTimeline } from './final';
import { REVEAL_MS } from './motion';
import {
    carriesRoom,
    CLOCK_MS,
    FANFARE_MS,
    LEAD_MS,
    MAX_NAMES,
    roomCues,
    sameMoment,
    STALE_MS,
} from './sound-cues';
import type { RoomCue, RoomEar } from './sound-cues';
import { standingGroups } from './standing-groups';

/*
 * When a room game sounds (ADR 025 "Sound", ADR 028, docs/frontend.md rule
 * 15 "Sound"): a cue plays when its moment begins and the page saw it
 * begin, once however often the state comes again, never for the moment a
 * page opened on; the countdown and the clock are one file each, placed to
 * end at `opens_ms` and `bell_ms`; the screen plays the room's moments and
 * the same whatever anyone answered; a phone plays its own answer sent,
 * one sound for every answer, and its verdict only from the reveal — and,
 * in a room without a screen (`room.screen` false, ADR 028 as amended
 * 2026-11-19), the room's moments as the screen would, but never the
 * winner's cheer. After the final's fanfare the screen alone cheers the
 * first place (ADR 030). The fixtures' rooms have no screen; a phone's own
 * tests put one in (`screened()`).
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
        { state: sent, now: bell - 5400 },
        { state: sent, now: bell - 2900 },
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

/** The same state in a room whose big screen is on (`room.screen`), or off. */
function withScreen(source: State, on = true): State {
    return { ...source, room: { ...source.room, screen: on } } as State;
}

/** The same ears in a room with its big screen on. */
function screened(ears: RoomEar[]): RoomEar[] {
    return ears.map((ear) => ({
        ...ear,
        state: ear.state === null ? null : withScreen(ear.state),
    }));
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

const overTimeline = finalTimeline(standingGroups(over.ranking, []), 'screen');
const winnerAt = overTimeline.winnerAt ?? -1;
/** The fixture's final has a first and a second place, then two players without points. */
const secondAt = overTimeline.opensAt[1];
/** Who holds the fixture's first place: the name said after the fanfare. */
const winner = over.ranking[0].player_id;

describe('roomCues on the screen', () => {
    it("plays each of the room's moments once, however often the state comes again", () => {
        const played = heard(game('right'), 'screen');

        expect(played.map(({ step, cue }) => [step, cue])).toEqual([
            [1, { sound: 'countdown', at: 2500, ends: true }],
            [6, { sound: 'study', at: 0 }],
            [8, { sound: 'question', at: 0 }],
            [12, { sound: 'clock', at: 5400, ends: true }],
            [17, { sound: 'close', at: 0 }],
            [17, { sound: 'ranking', at: REVEAL_MS.ranking }],
            [20, { sound: 'place', at: secondAt }],
            [20, { sound: 'roll', at: winnerAt, ends: true }],
            [20, { sound: 'fanfare', at: winnerAt }],
            [
                20,
                {
                    sound: 'cheer',
                    at: winnerAt + FANFARE_MS,
                    players: [winner],
                    many: false,
                },
            ],
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

    it('runs the clock once, as one file ending at the bell, heard ahead of its last five seconds', () => {
        const clocks = heard(game('right'), 'screen').filter(
            ({ cue }) => cue.sound === 'clock',
        );

        expect(clocks).toHaveLength(1);
        expect(game('right')[clocks[0].step].now).toBe(bell - 5400);
        expect(clocks[0].cue).toEqual({ sound: 'clock', at: 5400, ends: true });
        expect(CLOCK_MS + LEAD_MS).toBeGreaterThanOrEqual(5400);

        // Not yet, more than CLOCK_MS + LEAD_MS before the bell.
        expect(
            roomCues(
                { state: answer, now: bell - 9000 },
                { state: answer, now: bell - CLOCK_MS - LEAD_MS - 1 },
                'screen',
            ),
        ).toEqual([]);
    });

    it('places the countdown to end where round 0 opens, from its middle when it is heard late', () => {
        expect(
            roomCues(
                { state: lobby, now: opens - 4000 },
                { state: countdown, now: opens - 1200 },
                'screen',
            ),
        ).toEqual([{ sound: 'countdown', at: 1200, ends: true }]);
        // Without the server's clock there is no countdown to place.
        expect(
            roomCues(
                { state: lobby, now: null },
                { state: countdown, now: null },
                'screen',
            ),
        ).toEqual([]);
    });

    it('starts the game with «go»: round 0 opens with it in place of its study or its question', () => {
        const first = <T extends LiveState>(
            source: T,
            studied: boolean,
        ): State =>
            ({
                ...source,
                round: {
                    ...source.round,
                    index: 0,
                    answer_from_ms: studied
                        ? source.round.answer_from_ms
                        : source.round.opens_ms,
                },
            }) as State;
        const from = { state: countdown, now: opens - 100 };

        // A memory game: round 0's study is the start; its question is a question.
        expect(
            roomCues(
                from,
                { state: first(study, true), now: opens + 80 },
                'screen',
            ),
        ).toEqual([{ sound: 'go', at: 0 }]);
        expect(
            roomCues(
                { state: first(study, true), now: opens + 900 },
                {
                    state: first(answer as LiveState, true),
                    now: answerFrom + 60,
                },
                'screen',
            ),
        ).toEqual([{ sound: 'question', at: 0 }]);

        // A game without a study: round 0's question is the start.
        const opening = first(answer as LiveState, false);

        expect(
            roomCues(
                from,
                { state: opening, now: (opening.round?.opens_ms ?? 0) + 80 },
                'screen',
            ),
        ).toEqual([{ sound: 'go', at: 0 }]);
    });

    it('plays the clock with the question when the answer is shorter than the clock', () => {
        const short = {
            ...answer,
            round: { ...answer.round, bell_ms: answerFrom + 4000 },
        } as State;

        expect(
            roomCues(
                { state: study, now: answerFrom - 100 },
                { state: short, now: answerFrom + 60 },
                'screen',
            ),
        ).toEqual([
            { sound: 'question', at: 0 },
            { sound: 'clock', at: 3940, ends: true },
        ]);
    });

    it('ends the clock with its moment when everybody answered: the round closes early for the whole room', () => {
        const early = {
            ...reveal,
            round: { ...reveal.round, closes_ms: bell - 2000 },
        } as State;
        const before = { state: answered, now: bell - 2100 };
        const after = { state: early, now: bell - 1900 };

        expect(sameMoment(before, after, 'screen')).toBe(false);
        expect(
            roomCues(before, after, 'screen').map(({ sound }) => sound),
        ).toEqual(['close', 'ranking']);
    });

    it('hits the third and the second place as they open, then rolls to the first', () => {
        const five = {
            ...over,
            ranking: [
                { player_id: 'p1', points: 90, place: 1 },
                { player_id: 'p2', points: 70, place: 2 },
                { player_id: 'p3', points: 60, place: 3 },
                { player_id: 'p4', points: 30, place: 4 },
                { player_id: 'p5', points: 0, place: null },
            ],
        } as State;
        const timeline = finalTimeline(
            standingGroups(five.ranking, []),
            'screen',
        );

        expect(
            roomCues(
                { state: reveal, now: closes + 100 },
                { state: five, now: closes + 3100 },
                'screen',
            ),
        ).toEqual([
            { sound: 'place', at: timeline.opensAt[2] },
            { sound: 'place', at: timeline.opensAt[1] },
            { sound: 'roll', at: timeline.winnerAt, ends: true },
            { sound: 'fanfare', at: timeline.winnerAt },
            {
                sound: 'cheer',
                at: (timeline.winnerAt ?? 0) + FANFARE_MS,
                players: ['p1'],
                many: false,
            },
        ]);
    });

    it('plays «join» between games when a player appears, once a state, never during a game or for a player who leaves or comes back', () => {
        const extra = {
            id: '01kaqrx9zz9zz9zz9zz9zz9zz9',
            name: 'Yeni',
            present: true,
            host: false,
        };
        const joined = (source: State, count = 1): State =>
            ({
                ...source,
                players: [
                    ...source.players,
                    ...Array.from({ length: count }, (_, n) => ({
                        ...extra,
                        id: extra.id.slice(0, -1) + String(n),
                    })),
                ],
            }) as State;
        const away = {
            ...lobby,
            players: lobby.players.map((player, n) =>
                n === 1 ? { ...player, present: false } : player,
            ),
        } as State;

        expect(
            roomCues(
                { state: lobby, now: 1 },
                { state: joined(lobby, 3), now: 2 },
                'screen',
            ),
        ).toEqual([{ sound: 'join', at: 0 }]);
        expect(
            roomCues(
                { state: over, now: 1 },
                { state: joined(over), now: 2 },
                'screen',
            ),
        ).toEqual([{ sound: 'join', at: 0 }]);
        expect(
            roomCues(
                { state: joined(lobby), now: 1 },
                { state: lobby, now: 2 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: lobby, now: 1 },
                { state: away, now: 2 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: away, now: 1 },
                { state: lobby, now: 2 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: answered, now: answerFrom + 2000 },
                { state: joined(answered), now: answerFrom + 2100 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: withScreen(lobby), now: 1 },
                { state: withScreen(joined(lobby)), now: 2 },
                'phone',
            ),
        ).toEqual([]);
    });
});

describe("roomCues and the winner's cheer (ADR 030)", () => {
    /** The fixture's final with `first` holding the first place together, in this order, and the rest after them. */
    function sharedFirst(first: string[]): State {
        return {
            ...over,
            ranking: [
                ...first.map((player_id) => ({
                    player_id,
                    points: 74,
                    place: 1,
                })),
                { player_id: 'p9', points: 20, place: 2 },
            ],
        } as State;
    }

    const finalOf = (shown: State, speaker: RoomSpeaker = 'screen') =>
        roomCues(
            { state: reveal, now: closes + 100 },
            { state: shown, now: closes + 3100 },
            speaker,
        );

    it('cheers the winner after the fanfare: the first name, then «Əla oynadın!»', () => {
        const cues = finalOf(over);
        const fanfare = cues.findIndex(({ sound }) => sound === 'fanfare');
        const said = cues.findIndex(({ sound }) => sound === 'cheer');

        expect(said).toBe(fanfare + 1);
        // One winner: the singular phrase.
        expect(cues[said]).toEqual({
            sound: 'cheer',
            at: winnerAt + FANFARE_MS,
            players: [winner],
            many: false,
        });
        expect(cues[said].at).toBeGreaterThan(cues[fanfare].at);
        // The fanfare is the README's 2.30 s (scripts/room-sounds/README.md).
        expect(FANFARE_MS).toBe(2300);
    });

    it("names two or three who share the first place in the state's order, then «Əla oynadınız!», and none of four", () => {
        const cheered = (first: string[]) =>
            finalOf(sharedFirst(first)).find(({ sound }) => sound === 'cheer');

        // The server's order, never re-sorted; a shared place, the plural phrase.
        expect(cheered(['p2', 'p1'])).toMatchObject({
            players: ['p2', 'p1'],
            many: true,
        });
        expect(cheered(['p3', 'p1', 'p2'])).toMatchObject({
            players: ['p3', 'p1', 'p2'],
            many: true,
        });
        expect(MAX_NAMES).toBe(3);

        // Four share it: nobody is named, and «Əla oynadınız!» is said alone.
        expect(cheered(['p1', 'p2', 'p3', 'p4'])).toMatchObject({
            players: [],
            many: true,
        });
    });

    it('says nothing on a reload of the final, or when the state comes again', () => {
        // The page opened on the final: the first thing it hears.
        expect(roomCues(null, { state: over, now: 1000 }, 'screen')).toEqual(
            [],
        );
        // The final fetched again, a poll or a `room.changed`, is the same moment.
        expect(
            roomCues(
                { state: over, now: 1000 },
                { state: over, now: 4000 },
                'screen',
            ),
        ).toEqual([]);
    });

    it('cheers nobody when nobody holds the first place', () => {
        const nobody = {
            ...over,
            ranking: over.ranking.map((row) => ({
                ...row,
                points: 0,
                place: null,
            })),
        } as State;

        expect(finalOf(nobody)).toEqual([]);
    });

    it('never cheers on a phone, not even the winner on their own', () => {
        const mine = {
            ...over,
            me: { ...over.me, player_id: winner },
        } as State;

        expect(finalOf(withScreen(over), 'phone')).toEqual([]);
        expect(finalOf(withScreen(mine), 'phone')).toEqual([]);
        expect(
            names(heard(game('right'), 'phone')).filter(
                (sound) => sound === 'cheer',
            ),
        ).toEqual([]);

        // A phone that carries a room without a screen plays the final, never the cheer.
        for (const final of [over, mine]) {
            const played = finalOf(final, 'phone').map(({ sound }) => sound);

            expect(played).toContain('fanfare');
            expect(played).not.toContain('cheer');
        }
    });
});

describe('roomCues on a phone in a room with its screen', () => {
    it('plays the answer sent once, and the verdict only from the reveal', () => {
        expect(
            heard(screened(game('right')), 'phone').map(({ step, cue }) => [
                step,
                cue,
            ]),
        ).toEqual([
            [10, { sound: 'sent', at: 0 }],
            [17, { sound: 'right', at: 0 }],
        ]);
        expect(names(heard(screened(game('wrong')), 'phone'))).toEqual([
            'sent',
            'wrong',
        ]);
    });

    it("plays none of the room's moments", () => {
        const played = names(heard(screened(game('right')), 'phone'));

        for (const room of [
            'countdown',
            'go',
            'study',
            'question',
            'clock',
            'close',
            'ranking',
            'place',
            'roll',
            'fanfare',
            'join',
        ]) {
            expect(played).not.toContain(room);
        }
    });

    it('plays nothing at a reveal without an answer, or for a host who does not play', () => {
        expect(names(heard(screened(game('none')), 'phone'))).toEqual([]);

        const watching = (ear: RoomEar): RoomEar => ({
            ...ear,
            state:
                ear.state === null
                    ? null
                    : ({
                          ...withScreen(ear.state),
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
        expect(
            heard(screened(game('wrong')), 'phone').filter(
                ({ step }) => step < 17,
            ),
        ).toEqual(
            heard(screened(game('right')), 'phone').filter(
                ({ step }) => step < 17,
            ),
        );
    });
});

describe('roomCues on a phone in a room without a screen', () => {
    it('carries the room only while it is open and its screen is off', () => {
        expect(carriesRoom(lobby)).toBe(true);
        expect(carriesRoom(withScreen(lobby))).toBe(false);
        expect(
            carriesRoom({
                ...lobby,
                room: { ...lobby.room, status: 'closed', code: null },
            } as State),
        ).toBe(false);
        expect(carriesRoom(null)).toBe(false);
    });

    it("plays every one of the room's moments as the screen does but the cheer, and its own cues too", () => {
        for (const mine of ['right', 'wrong', 'none'] as const) {
            // All the screen plays but the cheer, the screen's one voice (ADR 030).
            const merged = [
                ...heard(game(mine), 'screen').filter(
                    ({ cue }) => cue.sound !== 'cheer',
                ),
                ...heard(screened(game(mine)), 'phone'),
            ].sort((a, b) => a.step - b.step);

            expect(heard(game(mine), 'phone')).toEqual(merged);
        }

        expect(names(heard(game('right'), 'phone'))).toEqual(
            expect.arrayContaining([
                'countdown',
                'clock',
                'close',
                'ranking',
                'fanfare',
                'sent',
                'right',
            ]),
        );
    });

    it('plays «join» in the lobby', () => {
        const joined = {
            ...lobby,
            players: [
                ...lobby.players,
                {
                    id: '01kaqrx9zz9zz9zz9zz9zz9zz9',
                    name: 'Yeni',
                    present: true,
                    host: false,
                },
            ],
        } as State;

        expect(
            roomCues(
                { state: lobby, now: 1 },
                { state: joined, now: 2 },
                'phone',
            ),
        ).toEqual([{ sound: 'join', at: 0 }]);
    });

    it('runs the clock and plays the answer sent when both come in one moment', () => {
        expect(
            roomCues(
                { state: answer, now: bell - 6000 },
                { state: answered, now: bell - 5400 },
                'phone',
            ),
        ).toEqual([
            { sound: 'clock', at: 5400, ends: true },
            { sound: 'sent', at: 0 },
        ]);
    });

    it('stops carrying once the screen comes on, and carries again when it goes', () => {
        expect(
            roomCues(
                { state: withScreen(lobby), now: opens - 5000 },
                { state: withScreen(countdown), now: opens - 2500 },
                'phone',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: withScreen(lobby), now: opens - 5000 },
                { state: countdown, now: opens - 2500 },
                'phone',
            ),
        ).toEqual([{ sound: 'countdown', at: 2500, ends: true }]);
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

        // Opened in the countdown: it is one moment, silent to the end.
        expect(
            heard(
                [
                    { state: countdown, now: opens - 1500 },
                    { state: countdown, now: opens - 1400 },
                    { state: countdown, now: opens - 500 },
                ],
                'screen',
            ),
        ).toEqual([]);

        // Opened in an answer's last seconds: the clock it opened in is not played.
        expect(
            heard(
                [
                    { state: answered, now: bell - 3000 },
                    { state: answered, now: bell - 2800 },
                    { state: answered, now: bell - 100 },
                ],
                'screen',
            ),
        ).toEqual([]);

        // Opened earlier in the answer: the clock begins after it, so it plays.
        expect(
            names(
                heard(
                    [
                        { state: answered, now: bell - 9000 },
                        { state: answered, now: bell - 5300 },
                    ],
                    'screen',
                ),
            ),
        ).toEqual(['clock']);

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
                { state: withScreen(answered), now: bell - 100 },
                { state: withScreen(reveal), now: closes + STALE_MS },
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
