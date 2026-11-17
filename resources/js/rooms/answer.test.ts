import { describe, expect, it, vi } from 'vite-plus/test';
import { RETRY_MS, eventId, sendAnswer } from './answer';
import { RoomAnswer } from './contract';
import type { Reply } from './http';

/*
 * A player's answer (docs/architecture.md §12 "An answer"): one event id
 * per answer, sent unchanged on every retry; the receipt is passed on as
 * the server gave it, and says nothing of right or wrong.
 */
const answer = RoomAnswer.parse({
    event_id: eventId(1_795_534_226_900),
    round_id: '01kaqs0n2p3q4r5s6t7v8w9xyz',
    picked: ['z3f7nq1h', 'd9k2mw6j', 'r5x8bt3k'],
});

function replies(...list: (Reply | Error)[]) {
    const sent: unknown[] = [];
    const post = vi.fn(async (body: unknown) => {
        sent.push(structuredClone(body));
        const next = list.shift();

        if (next === undefined || next instanceof Error) {
            throw next ?? new Error('offline');
        }

        return next;
    });

    return { post, sent };
}

const noWait = vi.fn(async () => {});

describe('eventId', () => {
    it("makes a ULID whose time is the server's clock", () => {
        const id = eventId(1_795_534_226_900, (bytes) => bytes.fill(31));

        expect(RoomAnswer.shape.event_id.safeParse(id).success).toBe(true);
        expect(id).toBe(id.toLowerCase());
        expect(id.slice(10)).toBe('z'.repeat(16));
        // The same moment gives the same time part; another moment, another.
        expect(eventId(1_795_534_226_900).slice(0, 10)).toBe(id.slice(0, 10));
        expect(eventId(1_795_534_226_901).slice(0, 10)).not.toBe(
            id.slice(0, 10),
        );
        expect(eventId(1_795_534_226_900)).not.toBe(eventId(1_795_534_226_900));
    });
});

describe('sendAnswer', () => {
    it('passes on every receipt as the server gave it', async () => {
        for (const status of ['accepted', 'duplicate', 'answered', 'late']) {
            const { post } = replies({
                status: 200,
                body: { status, revision: 7 },
            });

            await expect(
                sendAnswer({
                    answer,
                    post,
                    wait: noWait,
                    keepTrying: () => true,
                }),
            ).resolves.toEqual({
                kind: 'receipt',
                receipt: { status, revision: 7 },
            });
        }
    });

    it('sends the same event id again until a receipt comes', async () => {
        const { post, sent } = replies(
            new Error('offline'),
            { status: 500, body: null },
            { status: 429, body: null },
            { status: 200, body: { status: 'duplicate', revision: 3 } },
        );
        const wait = vi.fn(async (_ms: number) => {});

        const outcome = await sendAnswer({
            answer,
            post,
            wait,
            keepTrying: () => true,
        });

        expect(outcome).toEqual({
            kind: 'receipt',
            receipt: { status: 'duplicate', revision: 3 },
        });
        expect(sent).toEqual([answer, answer, answer, answer]);
        expect(wait.mock.calls.map(([ms]) => ms)).toEqual(RETRY_MS.slice(0, 3));
    });

    it('stops retrying once the page has moved on, or after the last wait', async () => {
        let showing = true;
        const moved = replies(new Error('offline'), new Error('offline'));

        await expect(
            sendAnswer({
                answer,
                post: moved.post,
                wait: async () => {
                    showing = false;
                },
                keepTrying: () => showing,
            }),
        ).resolves.toEqual({ kind: 'failed' });
        expect(moved.post).toHaveBeenCalledOnce();

        const down = replies();
        await expect(
            sendAnswer({
                answer,
                post: down.post,
                wait: noWait,
                keepTrying: () => true,
            }),
        ).resolves.toEqual({ kind: 'failed' });
        expect(down.post).toHaveBeenCalledTimes(RETRY_MS.length + 1);
    });

    it('does not retry an answer the server refused or a room that is gone', async () => {
        const refused = replies({ status: 422, body: { errors: {} } });
        await expect(
            sendAnswer({
                answer,
                post: refused.post,
                wait: noWait,
                keepTrying: () => true,
            }),
        ).resolves.toEqual({ kind: 'refused' });

        for (const status of [401, 403, 404, 419]) {
            const gone = replies({ status, body: null });
            await expect(
                sendAnswer({
                    answer,
                    post: gone.post,
                    wait: noWait,
                    keepTrying: () => true,
                }),
            ).resolves.toEqual({ kind: 'gone' });
            expect(gone.post).toHaveBeenCalledOnce();
        }
    });

    it('takes no receipt that says more than its status and revision', async () => {
        const { post } = replies(
            {
                status: 200,
                body: { status: 'accepted', revision: 1, correct: true },
            },
            { status: 200, body: { status: 'accepted', revision: 1 } },
        );

        await expect(
            sendAnswer({ answer, post, wait: noWait, keepTrying: () => true }),
        ).resolves.toEqual({
            kind: 'receipt',
            receipt: { status: 'accepted', revision: 1 },
        });
        expect(post).toHaveBeenCalledTimes(2);
    });
});
