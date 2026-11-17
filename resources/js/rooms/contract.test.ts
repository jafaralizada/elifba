import { describe, expect, it } from 'vite-plus/test';
import type { z } from 'zod';
import {
    RoomAnswer,
    RoomAnswerReceipt,
    RoomChangedEvent,
    RoomItem,
    RoomState,
} from './contract';

/*
 * Every fixture in tests/Fixtures/rooms parses with its strict schema; Pest
 * round-trips the same files through the PHP DTOs
 * (tests/Unit/Rooms/RoomContractTest.php) and builds the lobby from rows
 * (tests/Feature/Rooms/RoomStateTest.php). The prefix of a file name names
 * its schema. judge.json holds the cases of the server's judge, not a
 * payload a device receives: only its items are read here.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/rooms/*.json',
    { eager: true, import: 'default' },
);

const schemas: Record<string, z.ZodType> = {
    state: RoomState,
    answer: RoomAnswer,
    receipt: RoomAnswerReceipt,
};

function schemaOf(path: string): z.ZodType | undefined {
    const name = path.split('/').pop() ?? '';

    return schemas[name.slice(0, name.indexOf('.'))];
}

function fixture(name: string): unknown {
    return fixtures[`../../../tests/Fixtures/rooms/${name}.json`];
}

type State = z.infer<typeof RoomState>;
type Round = NonNullable<State['round']>;

const lobby = fixture('state.lobby') as State;
const payloads = Object.entries(fixtures).filter(
    ([path]) => !path.endsWith('/judge.json'),
);

describe('room fixtures', () => {
    it('accepts the lobby fixture', () => {
        const result = RoomState.safeParse(lobby);

        expect(result.error).toBeUndefined();
        expect(result.data).toEqual(lobby);
    });

    it('has a fixture for every phase, the answer and the receipt', () => {
        for (const name of [
            'state.lobby',
            'state.countdown',
            'state.study',
            'state.answer',
            'state.reveal',
            'state.over',
            'answer',
            'receipt',
        ]) {
            expect(fixture(name), name).toBeDefined();
        }
    });

    it.each(payloads)('%s parses strictly', (path, data) => {
        const schema = schemaOf(path);
        const result = schema?.safeParse(data);

        expect(schema, path).toBeDefined();
        expect(result?.error).toBeUndefined();
        expect(result?.data).toEqual(data);
    });

    it("reads the judge cases' items as items", () => {
        const judge = fixture('judge') as {
            cases: { items: unknown[] }[];
        };

        expect(judge.cases.length).toBeGreaterThan(0);

        for (const entry of judge.cases) {
            for (const item of entry.items) {
                expect(RoomItem.safeParse(item).error).toBeUndefined();
            }
        }
    });
});

describe('what a state never carries', () => {
    it('refuses a state that carries an account id or an address', () => {
        const [first, ...rest] = lobby.players;

        for (const extra of [
            { user_id: 7 },
            { account_id: 7 },
            { email: 'resad@example.com' },
            { ip: '10.0.0.7' },
        ]) {
            expect(
                RoomState.safeParse({
                    ...lobby,
                    players: [{ ...first, ...extra }, ...rest],
                }).success,
            ).toBe(false);
            expect(
                RoomState.safeParse({ ...lobby, me: { ...lobby.me, ...extra } })
                    .success,
            ).toBe(false);
            expect(
                RoomState.safeParse({
                    ...lobby,
                    room: { ...lobby.room, ...extra },
                }).success,
            ).toBe(false);
            expect(RoomState.safeParse({ ...lobby, ...extra }).success).toBe(
                false,
            );
        }
    });

    it('refuses a player known by anything but a row id', () => {
        const [first, ...rest] = lobby.players;

        expect(
            RoomState.safeParse({
                ...lobby,
                players: [{ ...first, id: 7 }, ...rest],
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({ ...lobby, me: { ...lobby.me, player_id: 7 } })
                .success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...lobby,
                ranking: [{ player_id: 7, points: 20, place: 1 }],
            }).success,
        ).toBe(false);
    });

    it('refuses a solution outside the reveal', () => {
        const reveal = fixture('state.reveal') as State;
        const revealed = (reveal.round as Extract<Round, { phase: 'reveal' }>)
            .reveal;

        for (const name of ['state.countdown', 'state.study', 'state.answer']) {
            const state = fixture(name) as State;
            const round = state.round as Round;

            // The reveal in an earlier phase, whole or in parts.
            for (const leak of [
                { reveal: revealed },
                { solution: revealed.solution },
                { reveal: { ...revealed, mine: null, results: [] } },
                { closes_ms: round.bell_ms, next_ms: round.bell_ms + 5000 },
            ]) {
                expect(
                    RoomState.safeParse({
                        ...state,
                        round: { ...round, ...leak },
                    }).success,
                    `${name} with ${Object.keys(leak).join(', ')}`,
                ).toBe(false);
            }

            // Nor beside the round, the game or a ranking row.
            expect(
                RoomState.safeParse({ ...state, solution: revealed.solution })
                    .success,
            ).toBe(false);
            expect(
                RoomState.safeParse({
                    ...state,
                    game: { ...state.game, solution: revealed.solution },
                }).success,
            ).toBe(false);
            expect(
                RoomState.safeParse({
                    ...state,
                    ranking: state.ranking.map((row) => ({
                        ...row,
                        correct: true,
                    })),
                }).success,
            ).toBe(false);
        }

        // A result or one's own answer never rides on the question.
        const answer = fixture('state.answer') as State;
        const asked = answer.round as Extract<Round, { phase: 'answer' }>;

        for (const leak of [
            { correct: true },
            { points: 19 },
            { mine: revealed.mine },
        ]) {
            expect(
                RoomState.safeParse({
                    ...answer,
                    round: { ...asked, ask: { ...asked.ask, ...leak } },
                }).success,
            ).toBe(false);
            expect(
                RoomState.safeParse({
                    ...answer,
                    round: {
                        ...asked,
                        ask: {
                            ...asked.ask,
                            items: asked.ask.items.map((item) => ({
                                ...item,
                                ...leak,
                            })),
                        },
                    },
                }).success,
            ).toBe(false);
        }
    });

    it('serves the material and the question each in its own phase', () => {
        const study = fixture('state.study') as State;
        const answer = fixture('state.answer') as State;
        const studied = study.round as Extract<Round, { phase: 'study' }>;
        const asked = answer.round as Extract<Round, { phase: 'answer' }>;
        const countdown = fixture('state.countdown') as State;

        // The material again in the answer phase; the question while studying or before.
        expect(
            RoomState.safeParse({
                ...answer,
                round: { ...asked, study: studied.study },
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...study,
                round: { ...studied, ask: asked.ask },
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...countdown,
                round: { ...countdown.round, study: studied.study },
            }).success,
        ).toBe(false);
        // Nobody has answered before the question.
        expect(
            RoomState.safeParse({
                ...study,
                round: { ...studied, answered: true, answered_count: 1 },
            }).success,
        ).toBe(false);
    });

    it('holds a round only while a game is live', () => {
        const answer = fixture('state.answer') as State;
        const over = fixture('state.over') as State;

        expect(RoomState.safeParse({ ...answer, game: null }).success).toBe(
            false,
        );
        expect(
            RoomState.safeParse({ ...over, round: answer.round }).success,
        ).toBe(false);
        expect(RoomState.safeParse({ ...answer, round: null }).success).toBe(
            false,
        );
    });
});

describe('an answer and its receipt', () => {
    const answer = fixture('answer') as z.infer<typeof RoomAnswer>;

    it('names no player and carries no time of the device', () => {
        for (const extra of [
            { player_id: lobby.me.player_id },
            { user_id: 7 },
            { elapsed_ms: 1200 },
            { sent_ms: 1795534226000 },
            { correct: true },
        ]) {
            expect(RoomAnswer.safeParse({ ...answer, ...extra }).success).toBe(
                false,
            );
        }
    });

    it('says what was done and never whether it was right', () => {
        expect(
            RoomAnswerReceipt.safeParse({
                status: 'accepted',
                revision: 5,
                correct: true,
            }).success,
        ).toBe(false);
        expect(
            RoomAnswerReceipt.safeParse({ status: 'wrong', revision: 5 })
                .success,
        ).toBe(false);
    });
});

describe('room.changed', () => {
    it('carries the revision and nothing else', () => {
        expect(RoomChangedEvent.safeParse({ v: 1, revision: 4 }).success).toBe(
            true,
        );
        expect(
            RoomChangedEvent.safeParse({ v: 1, revision: 4, name: 'Aygün' })
                .success,
        ).toBe(false);
    });
});
