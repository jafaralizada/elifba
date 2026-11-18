/**
 * A room's contract (docs/architecture.md §12 "The state", "The race",
 * "Room games", "Games first", ADR 021 decision 6, ADR 023 decision 5,
 * ADR 026 decision 11): what
 * `GET /rooms/{room}/state` answers (and the `state` of the room page's
 * props), what a device posts as its answer and the receipt it gets, and
 * what `room.changed` carries. The schemas are the source; every
 * TypeScript type is inferred from them, never written by hand. The PHP
 * side is `app/Domain/Rooms/Data/*`; both are pinned to
 * `tests/Fixtures/rooms/*.json` (Pest round-trips them, Vitest parses them
 * strictly), so a key added on one side fails until the fixtures and the
 * other side change too. JSON keys are snake_case.
 *
 * The schemas are strict and split by phase, so a key out of place fails
 * them: an account id, an address, the material to study after its phase,
 * the question before its phase, a solution or a result before the
 * reveal. A player is known by `room_players.id` alone. There is no judge,
 * clock or points rule here: the server alone judges, times and scores.
 *
 * The board's contract (`resources/js/game/contract.ts`) is another one and
 * shares nothing with this.
 */
import { z } from 'zod';

/** A room's, a game's, a round's id and a player's (`room_players.id`): ULIDs. */
const id = z.ulid();

/** A moment of the server's clock, Unix milliseconds. */
const moment = z.number().int().nonnegative();

const count = z.number().int().nonnegative();

/** An item's id: 8 characters of a–z and 0–9 that carry no position. */
const itemId = z.string().regex(/^[a-z0-9]{8}$/);

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

/** The mechanics a room plays: the six of §12 "Room games" (B3–B6). */
export const RoomMechanic = z.enum([
    'recall_order',
    'recall_cells',
    'next_in_sequence',
    'odd_one_out',
    'anagram',
    'spelling',
]);

export const RoomItem = z.strictObject({
    id: itemId,
    kind: z.enum(['digit', 'letter', 'word', 'number', 'shape', 'cell']),
    /** Cased on the server; content, Azerbaijani in every locale. */
    text: z.string().min(1).nullable(),
    asset: z.string().min(1).nullable(),
    /** `[col, row]`, for a cell of a grid. */
    cell: z.tuple([count, count]).nullable(),
});

const RoomLayout = z.enum(['row', 'grid']);

const RoomGrid = z.strictObject({
    cols: z.number().int().positive(),
    rows: z.number().int().positive(),
});

/** What a memory round shows to study, in the order shown; only in the phase `study`. */
export const RoomStudy = z.strictObject({
    layout: RoomLayout,
    grid: RoomGrid.nullable(),
    items: z.array(RoomItem).min(1),
});

/**
 * The question; from the phase `answer` on. For a sequence, never in the
 * order to tap. `shown` is what is shown with it and never picked (B5: the
 * row `next_in_sequence` continues; empty for the other mechanics);
 * `items` are what an answer is made of.
 */
export const RoomAsk = z.strictObject({
    shape: z.enum(['choice', 'sequence', 'set']),
    /** How many items an answer holds. */
    pick: z.number().int().positive(),
    layout: RoomLayout,
    grid: RoomGrid.nullable(),
    shown: z.array(RoomItem),
    items: z.array(RoomItem).min(1),
});

/** The viewer's own answer, in the reveal. */
export const RoomMine = z.strictObject({
    picked: z.array(itemId).min(1),
    correct: z.boolean(),
    points: z.number().int().min(0).max(20),
    elapsed_ms: count,
});

/** A player's result of the round, in the reveal; a player who did not answer has none. */
export const RoomResult = z.strictObject({
    player_id: id,
    correct: z.boolean(),
    points: z.number().int().min(0).max(20),
});

/** Only in the phase `reveal`, once the round is closed. */
export const RoomReveal = z.strictObject({
    /** The canonical ids: for a sequence, the question's ids in the order to tap. */
    solution: z.array(itemId).min(1),
    /**
     * The word of a word game (B6), as the round writes it: an anagram's
     * drawn word in its tiles' capitals, a spelling's right spelling.
     */
    word: z.string().min(1).nullable(),
    mine: RoomMine.nullable(),
    results: z.array(RoomResult),
});

const roundBase = {
    id,
    index: count,
    total: z.number().int().positive(),
    opens_ms: moment,
    answer_from_ms: moment,
    bell_ms: moment,
    present_count: count,
};

/** Before the round has its question, nobody has answered it. */
const unanswered = {
    answered: z.literal(false),
    answered_count: z.literal(0),
};

const answers = {
    answered: z.boolean(),
    answered_count: count,
};

/** Until the round closes, its close is unknown: an early close may come before the bell. */
const open = {
    closes_ms: z.null(),
    next_ms: z.null(),
};

/**
 * The current round of a live game, one shape per phase: the number and the
 * moments before round 0 opens; the material while it is studied; the
 * question from the answer phase; the solution and the results only in the
 * reveal.
 */
export const RoomRound = z.discriminatedUnion('phase', [
    z.strictObject({
        ...roundBase,
        ...unanswered,
        ...open,
        phase: z.literal('countdown'),
        index: z.literal(0),
        study: z.null(),
        ask: z.null(),
        reveal: z.null(),
    }),
    z.strictObject({
        ...roundBase,
        ...unanswered,
        ...open,
        phase: z.literal('study'),
        study: RoomStudy,
        ask: z.null(),
        reveal: z.null(),
    }),
    z.strictObject({
        ...roundBase,
        ...answers,
        ...open,
        phase: z.literal('answer'),
        study: z.null(),
        ask: RoomAsk,
        reveal: z.null(),
    }),
    z.strictObject({
        ...roundBase,
        ...answers,
        phase: z.literal('reveal'),
        closes_ms: moment,
        next_ms: moment,
        study: z.null(),
        ask: RoomAsk,
        reveal: RoomReveal,
    }),
]);

const gameBase = {
    id,
    mechanic: RoomMechanic,
    difficulty: z.number().int().min(1).max(4),
    rounds: z.number().int().positive(),
};

export const RoomGame = z.strictObject({
    ...gameBase,
    phase: z.enum(['live', 'over']),
});

/**
 * A row of the ranking: points over closed rounds, a dense place, or none
 * for a player with no points (listed after the places). The page labels a
 * place from its props (`place_labels`).
 */
export const RoomRankedPlayer = z.strictObject({
    player_id: id,
    points: count,
    place: z.number().int().positive().nullable(),
});

/**
 * The room's choice of the next game (G2): what every device shows before
 * the start, and `played` — the latest game is over and was exactly this
 * choice, so a page shows its final rather than the start screen. The
 * server derives it; a page compares nothing itself.
 */
export const RoomSetup = z.strictObject({
    mechanic: RoomMechanic,
    difficulty: z.number().int().min(1).max(4),
    rounds: z.number().int().positive(),
    played: z.boolean(),
});

/** The state's version: a page that meets another reloads itself (`fetchState`). */
export const ROOM_STATE_VERSION = 2;

const stateBase = {
    v: z.literal(ROOM_STATE_VERSION),
    revision: count,
    server_ms: moment,
    room: RoomSummary,
    me: RoomViewer,
    players: z.array(RoomPlayer),
    /** The live game's, over its closed rounds; the room's when no game is live. */
    ranking: z.array(RoomRankedPlayer),
    /** The host's choice of the next game; null in a closed room. */
    setup: RoomSetup.nullable(),
};

/** No game yet, a live game with its current round, or a game that is over. */
export const RoomState = z.union([
    z.strictObject({ ...stateBase, game: z.null(), round: z.null() }),
    z.strictObject({
        ...stateBase,
        game: z.strictObject({ ...gameBase, phase: z.literal('live') }),
        round: RoomRound,
    }),
    z.strictObject({
        ...stateBase,
        game: z.strictObject({ ...gameBase, phase: z.literal('over') }),
        round: z.null(),
    }),
]);

/** `POST /rooms/{room}/answers`: the device's own event id, the round, the items in the order tapped. Never a player. */
export const RoomAnswer = z.strictObject({
    event_id: id,
    round_id: id,
    picked: z.array(itemId).min(1).max(36),
});

/** The receipt of an answer: the same for a right and a wrong one. */
export const RoomAnswerReceipt = z.strictObject({
    status: z.enum(['accepted', 'duplicate', 'answered', 'late']),
    revision: count,
});

/** `room.changed` on `private-room.{id}`: the revision and nothing else. */
export const RoomChangedEvent = z.strictObject({
    v: z.literal(1),
    revision: count,
});

export type RoomStatus = z.infer<typeof RoomStatus>;
export type RoomSummary = z.infer<typeof RoomSummary>;
export type RoomViewer = z.infer<typeof RoomViewer>;
export type RoomPlayer = z.infer<typeof RoomPlayer>;
export type RoomMechanic = z.infer<typeof RoomMechanic>;
export type RoomItem = z.infer<typeof RoomItem>;
export type RoomStudy = z.infer<typeof RoomStudy>;
export type RoomAsk = z.infer<typeof RoomAsk>;
export type RoomMine = z.infer<typeof RoomMine>;
export type RoomResult = z.infer<typeof RoomResult>;
export type RoomReveal = z.infer<typeof RoomReveal>;
export type RoomRound = z.infer<typeof RoomRound>;
export type RoomGame = z.infer<typeof RoomGame>;
export type RoomSetup = z.infer<typeof RoomSetup>;
export type RoomRankedPlayer = z.infer<typeof RoomRankedPlayer>;
export type RoomState = z.infer<typeof RoomState>;
export type RoomAnswer = z.infer<typeof RoomAnswer>;
export type RoomAnswerReceipt = z.infer<typeof RoomAnswerReceipt>;
export type RoomChangedEvent = z.infer<typeof RoomChangedEvent>;
