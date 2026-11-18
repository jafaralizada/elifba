/**
 * A player's taunt (docs/architecture.md §12 "Taunts (T2)", ADR 029
 * decision 13): `POST /rooms/{room}/taunts` `{event_id, target_id, taunt}`.
 * The event id is one ULID per tap (`eventId()`), sent again unchanged on a
 * retry, so a taunt that reached the server is stored and paid for once.
 * The receipt says what was done — `accepted`, `duplicate`, `late`, `off`,
 * `cooling`, `no_points` — and the page reads its new balance and cooldown
 * from the state it asks for next. The thrower is the session's account:
 * the body never names it.
 */
import { RoomTauntReceipt } from './contract';
import type { RoomTaunt } from './contract';
import type { Reply } from './http';

export type TauntOutcome =
    | { kind: 'receipt'; receipt: RoomTauntReceipt }
    /** 422: the rival is no present player of the room any more; nothing was stored. */
    | { kind: 'refused' }
    /** 401, 403, 404, 419: left, removed, the room closed, the session over. */
    | { kind: 'gone' }
    /** Nothing usable came back. */
    | { kind: 'failed' };

/** Waits before each retry: a taunt is sent again soon, twice at most. */
export const TAUNT_RETRY_MS = [400, 1200] as const;

/** Sends the taunt, and the same body again while nothing usable comes back. */
export async function sendTaunt(options: {
    taunt: RoomTaunt;
    post: (taunt: RoomTaunt) => Promise<Reply>;
    wait: (ms: number) => Promise<void>;
}): Promise<TauntOutcome> {
    for (let attempt = 0; ; attempt++) {
        let reply: Reply | null = null;

        try {
            reply = await options.post(options.taunt);
        } catch {
            reply = null;
        }

        if (reply !== null) {
            if (reply.status === 200) {
                const receipt = RoomTauntReceipt.safeParse(reply.body);

                if (receipt.success) {
                    return { kind: 'receipt', receipt: receipt.data };
                }
            } else if (reply.status === 422) {
                return { kind: 'refused' };
            } else if ([401, 403, 404, 419].includes(reply.status)) {
                return { kind: 'gone' };
            }
        }

        if (attempt >= TAUNT_RETRY_MS.length) {
            return { kind: 'failed' };
        }

        await options.wait(TAUNT_RETRY_MS[attempt]);
    }
}
