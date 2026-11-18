/**
 * What a room page draws of the live voice (docs/frontend.md rule 15 "Live
 * voice"; architecture §12 "Live voice", "The page (C4)"; ADR 031 decisions
 * 8, 11, 12, 26 and 34): `voiceView(state, connection)` for a phone and
 * `screenVoiceView(state)` for the big screen. Pure: it reads the state —
 * who may what — and, on a phone, what its own voice connection says —
 * who is there, whose microphone is open, who speaks — and names everyone
 * from the state's `players` by the row's id, as it names everyone. It
 * imports nothing of the voice connection, so the screen can use it and
 * still reach no voice code (`RoomsApartTest`).
 */
import type { RoomPlayer, RoomState, RoomVoiceStanding } from './contract';

/** What the voice connection says now (ADR 031 decision 5): drawn and forgotten, never stored, never sent. */
export type VoiceLive = {
    /** The rows in the voice, the viewer's own included. */
    here: string[];
    /** The rows whose microphone is open. */
    open: string[];
    /** The rows speaking now. */
    speaking: string[];
};

/** Why the page is not in the voice although it tried (ADR 031 decision 26). */
export type VoiceTrouble =
    /** The voice server could not be reached, or a ticket could not be had. */
    | 'unreachable'
    /** The connection dropped and the library's own tries ran out. */
    | 'dropped'
    /** The same account joined from another device, which now has the voice. */
    | 'elsewhere';

/** The viewer's own voice connection as `rooms/voice.ts` reports it. */
export type VoiceConnection =
    /** Not in the voice: before a join, after «Səsdən çıx», or refused. */
    | { kind: 'idle' }
    | { kind: 'joining' }
    | {
          kind: 'in';
          live: VoiceLive;
          /** The viewer's own microphone: `opening` while the browser asks. */
          mic: 'off' | 'opening' | 'on';
          /** The library is resuming a dropped connection, or the page asks for a new ticket. */
          reconnecting: boolean;
      }
    | { kind: 'failed'; trouble: VoiceTrouble };

/** A row's state in the sheet «Canlı səs», each said in words with its icon. */
export type VoiceRowState =
    | 'speaking'
    | 'mic'
    | 'listening'
    | 'away'
    | 'muted'
    | 'waiting';

/** The host's one button on a row: «Susdur», «Danışmağa icazə ver» or «Səsə burax». */
export type VoiceRowAction = 'mute' | 'unmute' | 'admit';

export type VoiceRow = {
    id: string;
    /** The account's name as the state gives it; null for «Oyunçu». */
    name: string | null;
    mine: boolean;
    host: boolean;
    state: VoiceRowState;
    /** For the host only, never on the host's own row. */
    action: VoiceRowAction | null;
};

/** Who speaks, in words: «Siz danışırsınız» only while the viewer alone speaks. */
export type VoiceSpeaker =
    | { kind: 'none' }
    | { kind: 'you' }
    | { kind: 'one'; name: string | null }
    | { kind: 'two'; name: string | null; other: string | null }
    | { kind: 'many'; count: number };

/** The bar's four forms (rule 15 "The voice bar"), and the one line of an insecure page. */
export type VoiceBarForm =
    | { form: 'insecure' }
    | { form: 'waiting' }
    | { form: 'invited'; agreed: boolean; joining: boolean }
    | {
          form: 'in';
          speaker: VoiceSpeaker;
          count: number;
          mic: 'off' | 'opening' | 'on' | 'muted';
      }
    | {
          form: 'trouble';
          trouble: VoiceTrouble | 'reconnecting';
      };

export type VoiceView = {
    /** The viewer is the room's host: the sheet's buttons and the line for a waiting newcomer. */
    host: boolean;
    bar: VoiceBarForm;
    /** For the host: the first newcomer who waits to be let in, shown above the bar; null otherwise. */
    waiting: { id: string; name: string | null } | null;
    /** The sheet «Canlı səs»: the host's row and each present player's, those who speak first. */
    rows: VoiceRow[];
};

/** The viewer's own row: the player's, or the host's own row for a host who does not play. */
export function myVoiceRow(state: RoomState): RoomPlayer | null {
    if (state.me.player_id !== null) {
        return (
            state.players.find((player) => player.id === state.me.player_id) ??
            null
        );
    }

    return state.me.host
        ? (state.players.find((player) => player.host) ?? null)
        : null;
}

const IDLE: VoiceConnection = { kind: 'idle' };

/**
 * A phone's voice: null — nothing of voice is drawn — while the state has
 * no voice (the instance's switch is off, a closed room) or the host has
 * it off, and for a viewer without a standing (a player who left). A
 * waiting newcomer is offered no join, and a page that is not a secure
 * context is not offered the voice at all, only one line.
 */
export function voiceView(
    state: RoomState,
    connection: VoiceConnection = IDLE,
    secure = true,
): VoiceView | null {
    if (state.voice === null || !state.voice.on) {
        return null;
    }

    const me = myVoiceRow(state);

    if (me === null || me.voice === null) {
        return null;
    }

    const host = state.me.host;
    const live =
        connection.kind === 'in'
            ? connection.live
            : { here: [], open: [], speaking: [] };
    const rows = sheetRows(state, me.id, live, host);
    const waitingRow = host
        ? (state.players.find(
              (player) => !player.host && player.voice === 'waiting',
          ) ?? null)
        : null;
    const waiting =
        waitingRow === null
            ? null
            : { id: waitingRow.id, name: waitingRow.name };

    return { host, bar: barForm(state, me, connection, secure), waiting, rows };
}

function barForm(
    state: RoomState,
    me: RoomPlayer,
    connection: VoiceConnection,
    secure: boolean,
): VoiceBarForm {
    if (!secure) {
        return { form: 'insecure' };
    }

    if (me.voice === 'waiting') {
        return { form: 'waiting' };
    }

    switch (connection.kind) {
        case 'idle':
            return {
                form: 'invited',
                agreed: state.voice?.agreed ?? false,
                joining: false,
            };
        case 'joining':
            return {
                form: 'invited',
                agreed: state.voice?.agreed ?? false,
                joining: true,
            };
        case 'failed':
            return { form: 'trouble', trouble: connection.trouble };
        case 'in':
            if (connection.reconnecting) {
                return { form: 'trouble', trouble: 'reconnecting' };
            }

            return {
                form: 'in',
                speaker: speakerOf(state, me.id, connection.live.speaking),
                count: connection.live.here.length,
                mic: me.voice === 'muted' ? 'muted' : connection.mic,
            };
    }
}

/** Who speaks, named from the state's rows; the viewer is left out of a list that has others. */
function speakerOf(
    state: RoomState,
    me: string,
    speaking: string[],
): VoiceSpeaker {
    const known = speaking.filter((id) =>
        state.players.some((player) => player.id === id),
    );
    const others = known.filter((id) => id !== me);
    const name = (id: string) =>
        state.players.find((player) => player.id === id)?.name ?? null;

    if (others.length === 0) {
        return known.includes(me) ? { kind: 'you' } : { kind: 'none' };
    }

    if (others.length === 1) {
        return { kind: 'one', name: name(others[0]) };
    }

    if (others.length === 2) {
        return { kind: 'two', name: name(others[0]), other: name(others[1]) };
    }

    return { kind: 'many', count: others.length };
}

function sheetRows(
    state: RoomState,
    me: string,
    live: VoiceLive,
    host: boolean,
): VoiceRow[] {
    const rows = state.players
        .filter((player) => player.voice !== null)
        .map((player): VoiceRow => {
            const standing = player.voice as RoomVoiceStanding;

            return {
                id: player.id,
                name: player.name,
                mine: player.id === me,
                host: player.host,
                state: rowState(standing, player.id, live),
                action: host && !player.host ? actionOf(standing) : null,
            };
        });
    // Those who speak first, then the list's own order: never anything counted.
    const speaking = rows.filter((row) => row.state === 'speaking');

    return [...speaking, ...rows.filter((row) => row.state !== 'speaking')];
}

function rowState(
    standing: RoomVoiceStanding,
    id: string,
    live: VoiceLive,
): VoiceRowState {
    if (standing === 'waiting') {
        return 'waiting';
    }

    if (standing === 'muted') {
        return 'muted';
    }

    if (live.speaking.includes(id)) {
        return 'speaking';
    }

    if (live.open.includes(id)) {
        return 'mic';
    }

    return live.here.includes(id) ? 'listening' : 'away';
}

function actionOf(standing: RoomVoiceStanding): VoiceRowAction {
    switch (standing) {
        case 'open':
            return 'mute';
        case 'muted':
            return 'unmute';
        case 'waiting':
            return 'admit';
    }
}

/** The big screen's voice: only the state's facts — that the voice is on, who waits, who is muted. */
export type ScreenVoiceView = {
    marks: Record<string, 'waiting' | 'muted'>;
};

/**
 * The screen joins no voice (ADR 031 decisions 18, 25): it shows that the
 * voice is on, and a waiting or muted player's mark, from the state alone —
 * it has no connection to ask who is there or who speaks.
 */
export function screenVoiceView(state: RoomState): ScreenVoiceView | null {
    if (state.voice === null || !state.voice.on) {
        return null;
    }

    const marks: Record<string, 'waiting' | 'muted'> = {};

    for (const player of state.players) {
        if (
            !player.host &&
            (player.voice === 'waiting' || player.voice === 'muted')
        ) {
            marks[player.id] = player.voice;
        }
    }

    return { marks };
}
