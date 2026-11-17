/**
 * A room's contract (docs/architecture.md §12 "The state", "Room games",
 * ADR 023 decision 5): what `GET /rooms/{room}/state` answers and what
 * `room.changed` carries. The schemas are the source; every TypeScript type
 * is inferred from them, never written by hand. The PHP side is
 * `app/Domain/Rooms/Data/*`; both are pinned to `tests/Fixtures/rooms/*.json`
 * (Pest round-trips them, Vitest parses them strictly), so a key added on
 * one side fails until the fixtures and the other side change too. JSON
 * keys are snake_case.
 *
 * The schemas are strict, so a key out of place — an account id, an
 * address, a solution — fails them. A player is known by `room_players.id`
 * alone. B2 serves a room without a game: `game` and `round` are null and
 * `ranking` is empty; B3 gives them their schemas.
 *
 * The board's contract (`resources/js/game/contract.ts`) is another one and
 * shares nothing with this.
 */
import { z } from 'zod';

/** A room's id and a player's (`room_players.id`): ULIDs. */
const id = z.ulid();

export const RoomStatus = z.enum(['open', 'closed']);

export const RoomSummary = z.strictObject({
    id,
    status: RoomStatus,
    locked: z.boolean(),
    /** While the room is open. */
    code: z
        .string()
        .regex(/^\d{6}$/)
        .nullable(),
});

export const RoomViewer = z.strictObject({
    /** Null for a host who does not play. */
    player_id: id.nullable(),
    host: z.boolean(),
});

export const RoomPlayer = z.strictObject({
    id,
    /** The account's name as it is now; null for a row without an account («Oyunçu»). */
    name: z.string().min(1).nullable(),
    present: z.boolean(),
    host: z.boolean(),
});

export const RoomState = z.strictObject({
    v: z.literal(1),
    revision: z.number().int().nonnegative(),
    server_ms: z.number().int().nonnegative(),
    room: RoomSummary,
    me: RoomViewer,
    players: z.array(RoomPlayer),
    game: z.null(),
    round: z.null(),
    ranking: z.array(z.never()).length(0),
});

/** `room.changed` on `private-room.{id}`: the revision and nothing else. */
export const RoomChangedEvent = z.strictObject({
    v: z.literal(1),
    revision: z.number().int().nonnegative(),
});

export type RoomStatus = z.infer<typeof RoomStatus>;
export type RoomSummary = z.infer<typeof RoomSummary>;
export type RoomViewer = z.infer<typeof RoomViewer>;
export type RoomPlayer = z.infer<typeof RoomPlayer>;
export type RoomState = z.infer<typeof RoomState>;
export type RoomChangedEvent = z.infer<typeof RoomChangedEvent>;
