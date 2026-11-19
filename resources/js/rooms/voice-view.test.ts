import { describe, expect, it, vi } from 'vite-plus/test';
import { RoomState } from './contract';
import { VOICE_OUT_KEEP, VOICE_OUT_KEY, VoiceOut } from './voice-out';
import type { VoiceOutStorage } from './voice-out';
import {
    joinsByItself,
    mayJoinByItself,
    myVoiceRow,
    opensSentenceByItself,
    screenVoiceView,
    voiceView,
} from './voice-view';
import type { VoiceBrowser, VoiceConnection, VoiceLive } from './voice-view';

/*
 * What a room page draws of the live voice (docs/architecture.md §12 "Live
 * voice", "Guard tests"; docs/frontend.md rule 15 "Live voice"): from the
 * state — who may what — and, on a phone, from its own connection — who is
 * there, whose microphone is open, who speaks — named from the state's
 * players by the row's id. The screen gets the state's facts and nothing
 * else. The fixtures are C3's: tests/Fixtures/rooms/state.voice.json (a
 * player's start screen, the voice on: the host and Aygün open, Tural
 * muted, Leyla waiting) and state.voice.off.json (the host's, the voice
 * off). As amended 2026-11-19 (ADR 031 decisions 36 and 40–42): when a
 * page joins the voice or opens the sentence by itself, what the browser
 * says of the page — a held voice, the microphone's question — and the
 * rooms this device left (`voice-out.ts`).
 */
const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/rooms/state*.json',
    { eager: true, import: 'default' },
);

function state(name: string): RoomState {
    return RoomState.parse(
        fixtures[`../../../tests/Fixtures/rooms/${name}.json`],
    );
}

const on = state('state.voice');
const HOST = '01kaqrx2ab3c4d5e6f7g8h9jkm';
const AYGUN = '01kaqrx3mn4p5q6r7s8t9vwxyz';
const TURAL = '01kaqrx4bc5d6e7f8g9h0jkmnp';
const LEYLA = '01kaqrx6de7f8g9h0jkmnpqrst';

function inVoice(
    live: Partial<VoiceLive>,
    mic: 'off' | 'on' = 'off',
    captured = mic === 'on',
): VoiceConnection {
    return {
        kind: 'in',
        live: { here: [], open: [], speaking: [], ...live },
        mic,
        captured,
        reconnecting: false,
    };
}

/** The same state seen by another row. */
function as(source: RoomState, me: string, host: boolean): RoomState {
    return { ...source, me: { player_id: me, host } };
}

describe('voiceView', () => {
    it('draws nothing of voice while the state has none', () => {
        for (const [name, source] of Object.entries(fixtures)) {
            const parsed = RoomState.safeParse(source);

            if (!parsed.success || parsed.data.voice !== null) {
                continue;
            }

            expect(voiceView(parsed.data), name).toBeNull();
            expect(screenVoiceView(parsed.data), name).toBeNull();
        }

        // The voice there but off: neither the bar nor the screen's chip.
        const off = state('state.voice.off');
        expect(voiceView(off)).toBeNull();
        expect(screenVoiceView(off)).toBeNull();
        // A player without a standing (one who left) sees nothing either.
        expect(
            voiceView(as(on, '01kaqrx5cd6e7f8g9h0jkmnpqr', false)),
        ).toBeNull();
    });

    it('offers a waiting newcomer no join', () => {
        const leyla = as(on, LEYLA, false);

        expect(voiceView(leyla)?.bar).toEqual({ form: 'waiting' });
        // Even with a connection report, which a waiting row cannot have: still no join.
        expect(voiceView(leyla, inVoice({ here: [LEYLA] }))?.bar.form).toBe(
            'waiting',
        );
        // An admitted player is invited, with the sentence first until it was accepted.
        expect(voiceView(on)?.bar).toEqual({
            form: 'invited',
            agreed: true,
            joining: false,
        });
        expect(
            voiceView({ ...on, voice: { on: true, agreed: false } })?.bar,
        ).toEqual({ form: 'invited', agreed: false, joining: false });
        // The host sees the first newcomer who waits, to let them in; a player does not.
        const host = as(on, HOST, true);
        expect(voiceView(host)?.waiting).toEqual({
            id: LEYLA,
            name: 'Leyla Həsənova',
        });
        expect(voiceView(on)?.waiting).toBeNull();
    });

    it("names a speaker from the state's players by the row's id", () => {
        const speaking = (ids: string[]) =>
            voiceView(
                on,
                inVoice({ here: [HOST, AYGUN, TURAL], speaking: ids }),
            )?.bar;

        expect(speaking([])).toMatchObject({
            form: 'in',
            speaker: { kind: 'none' },
            count: 3,
        });
        expect(speaking([HOST])).toMatchObject({
            speaker: { kind: 'one', name: 'Rəşad Quliyev' },
        });
        // The viewer alone: «Siz danışırsınız»; with others, the others are named.
        expect(speaking([AYGUN])).toMatchObject({ speaker: { kind: 'you' } });
        expect(speaking([AYGUN, HOST])).toMatchObject({
            speaker: { kind: 'one', name: 'Rəşad Quliyev' },
        });
        expect(speaking([HOST, TURAL])).toMatchObject({
            speaker: {
                kind: 'two',
                name: 'Rəşad Quliyev',
                other: 'Tural Əliyev',
            },
        });
        expect(
            voiceView(
                as(on, LEYLA, false),
                inVoice({ speaking: [HOST, AYGUN, TURAL] }),
            )?.bar.form,
        ).toBe('waiting');
        expect(
            voiceView(
                as(on, TURAL, false),
                inVoice({ speaking: [HOST, AYGUN, LEYLA] }),
            )?.bar,
        ).toMatchObject({ speaker: { kind: 'many', count: 3 }, mic: 'muted' });
        // An id the state does not know is nobody: no name from the voice connection.
        expect(speaking(['01kaqrx9zz9zz9zz9zz9zz9zzz'])).toMatchObject({
            speaker: { kind: 'none' },
        });
    });

    it("lists the host and the present players, those who speak first, then in the list's own order", () => {
        const view = voiceView(
            as(on, HOST, true),
            inVoice(
                {
                    here: [HOST, AYGUN, TURAL],
                    open: [HOST, AYGUN],
                    speaking: [TURAL],
                },
                'on',
            ),
        );

        expect(
            view?.rows.map((row) => [row.id, row.state, row.action]),
        ).toEqual([
            // Tural is muted: a muted row says so even while the voice server still hears it.
            [HOST, 'mic', null],
            [AYGUN, 'mic', 'mute'],
            [TURAL, 'muted', 'unmute'],
            [LEYLA, 'waiting', 'admit'],
        ]);
        expect(view?.rows.find((row) => row.id === HOST)?.mine).toBe(true);

        const speakingFirst = voiceView(
            on,
            inVoice({ here: [HOST, AYGUN], speaking: [AYGUN] }),
        );
        // Someone not in the voice says so.
        expect(
            voiceView(on, inVoice({ here: [AYGUN] }))?.rows.find(
                (row) => row.id === HOST,
            )?.state,
        ).toBe('away');
        expect(
            speakingFirst?.rows.map((row) => [row.id, row.state, row.action]),
        ).toEqual([
            [AYGUN, 'speaking', null],
            [HOST, 'listening', null],
            [TURAL, 'muted', null],
            [LEYLA, 'waiting', null],
        ]);
    });

    it('says the microphone as its owner has it, and the trouble in words', () => {
        expect(
            voiceView(on, inVoice({ here: [AYGUN] }, 'on'))?.bar,
        ).toMatchObject({ mic: 'on' });
        expect(voiceView(on, { kind: 'joining' })?.bar).toEqual({
            form: 'invited',
            agreed: true,
            joining: true,
        });
        expect(
            voiceView(on, {
                kind: 'in',
                live: { here: [], open: [], speaking: [] },
                mic: 'off',
                captured: false,
                reconnecting: true,
            })?.bar,
        ).toEqual({ form: 'trouble', trouble: 'reconnecting' });
        for (const trouble of [
            'unreachable',
            'dropped',
            'elsewhere',
            'busy',
        ] as const) {
            expect(voiceView(on, { kind: 'failed', trouble })?.bar).toEqual({
                form: 'trouble',
                trouble,
            });
        }
        // Plain http on a LAN address: the voice is not offered, one line.
        expect(voiceView(on, { kind: 'idle' }, false)?.bar).toEqual({
            form: 'insecure',
        });
    });

    it("finds the host's own row for a host who does not play", () => {
        const away = as(on, HOST, true);

        expect(
            myVoiceRow({ ...away, me: { player_id: null, host: true } })?.id,
        ).toBe(HOST);
        expect(
            myVoiceRow({ ...away, me: { player_id: null, host: false } }),
        ).toBeNull();
    });
});

describe('the voice on by default', () => {
    const idle: VoiceConnection = { kind: 'idle' };
    const fresh: RoomState = { ...on, voice: { on: true, agreed: false } };

    it('joins by itself only an admitted player whose account agreed and who did not leave that voice on this device', () => {
        // Aygün, admitted and agreed, and the host: in without a tap; Tural, muted by the host, listens.
        expect(joinsByItself(on, idle, false)).toBe(true);
        expect(joinsByItself(as(on, HOST, true), idle, false)).toBe(true);
        expect(joinsByItself(as(on, TURAL, false), idle, false)).toBe(true);
        // Leyla waits for the host; an account that has not agreed gets the sentence, not the voice.
        expect(joinsByItself(as(on, LEYLA, false), idle, false)).toBe(false);
        expect(joinsByItself(fresh, idle, false)).toBe(false);
        // Left or declined on this device: never by itself.
        expect(joinsByItself(on, idle, true)).toBe(false);
        // The voice off or none, a player who left the room, an insecure page: nothing.
        expect(joinsByItself(state('state.voice.off'), idle, false)).toBe(
            false,
        );
        expect(joinsByItself(state('state.lobby'), idle, false)).toBe(false);
        expect(
            joinsByItself(
                as(on, '01kaqrx5cd6e7f8g9h0jkmnpqr', false),
                idle,
                false,
            ),
        ).toBe(false);
        expect(joinsByItself(on, idle, false, false)).toBe(false);
        // Already joining or in: nothing more.
        expect(joinsByItself(on, { kind: 'joining' }, false)).toBe(false);
        expect(joinsByItself(on, inVoice({ here: [AYGUN] }), false)).toBe(
            false,
        );
    });

    it('never joins by itself after a failure', () => {
        for (const trouble of [
            'unreachable',
            'dropped',
            'elsewhere',
            'busy',
        ] as const) {
            expect(joinsByItself(on, { kind: 'failed', trouble }, false)).toBe(
                false,
            );
        }

        // The page may still be in it — and joins only when that becomes so: the failure waits for a tap.
        expect(mayJoinByItself(on, false)).toBe(true);
        expect(mayJoinByItself(on, true)).toBe(false);
    });

    it('opens the sentence by itself only outside a round, and not after «İndi yox»', () => {
        // The same viewer, admitted and not agreed, in a round and at the final.
        const at = (name: string): RoomState => ({
            ...state(name),
            me: fresh.me,
            players: fresh.players,
            voice: fresh.voice,
        });

        // The start screen, and the final.
        expect(opensSentenceByItself(fresh, false)).toBe(true);
        expect(at('state.over').game?.phase).toBe('over');
        expect(opensSentenceByItself(at('state.over'), false)).toBe(true);
        // Never over a round: it waits for the game to end.
        for (const name of [
            'state.countdown',
            'state.answer',
            'state.reveal',
        ]) {
            expect(at(name).game?.phase, name).toBe('live');
            expect(opensSentenceByItself(at(name), false), name).toBe(false);
        }
        // «İndi yox» on this device, an account that agreed, a newcomer who waits, the voice off, an insecure page.
        expect(opensSentenceByItself(fresh, true)).toBe(false);
        expect(opensSentenceByItself(on, false)).toBe(false);
        expect(opensSentenceByItself(as(fresh, LEYLA, false), false)).toBe(
            false,
        );
        expect(
            opensSentenceByItself(
                { ...fresh, voice: { on: false, agreed: false } },
                false,
            ),
        ).toBe(false);
        expect(opensSentenceByItself(fresh, false, false)).toBe(false);
    });

    it('offers the line to always allow while the browser would ask', () => {
        const bar = (
            question: VoiceBrowser['question'],
            mic: 'off' | 'on' = 'off',
            captured = false,
            viewer = on,
        ) =>
            voiceView(viewer, inVoice({ here: [AYGUN] }, mic, captured), true, {
                held: false,
                question,
            })?.bar;

        expect(bar('prompt')).toMatchObject({ form: 'in', alwaysAllow: true });
        expect(bar('unknown')).toMatchObject({ alwaysAllow: true });
        expect(bar('granted')).toMatchObject({ alwaysAllow: false });
        expect(bar('denied')).toMatchObject({ alwaysAllow: false });
        // The page holds a capture: opening it asks nothing. Nor while it is open, nor for a muted player.
        expect(bar('prompt', 'off', true)).toMatchObject({
            alwaysAllow: false,
        });
        expect(bar('prompt', 'on')).toMatchObject({ alwaysAllow: false });
        expect(bar('prompt', 'off', false, as(on, TURAL, false))).toMatchObject(
            { mic: 'muted', alwaysAllow: false },
        );
    });

    it('says to tap while the browser holds the voice back, and stops at the first tap', () => {
        const held: VoiceBrowser = { held: true, question: 'granted' };
        const heard = inVoice({ here: [HOST, AYGUN] });

        expect(voiceView(on, heard, true, held)?.bar).toMatchObject({
            form: 'in',
            held: true,
        });
        // The tap plays every voice: nothing is held any more.
        expect(
            voiceView(on, heard, true, { held: false, question: 'granted' })
                ?.bar,
        ).toMatchObject({ held: false });
        // A page that captures is played to.
        expect(
            voiceView(on, inVoice({ here: [AYGUN] }, 'off', true), true, held)
                ?.bar,
        ).toMatchObject({ held: false });
        // Out of the voice there is nothing to hear.
        expect(voiceView(on, idle, true, held)?.bar.form).toBe('invited');
    });
});

describe('VoiceOut', () => {
    it('keeps the last twenty rooms left, and survives a storage that throws', () => {
        const kept = new Map<string, string>();
        const storage: VoiceOutStorage = {
            getItem: (key) => kept.get(key) ?? null,
            setItem: (key, value) => {
                kept.set(key, value);
            },
        };
        const out = new VoiceOut(() => storage);
        const stored = () =>
            JSON.parse(kept.get(VOICE_OUT_KEY) ?? 'null') as unknown;

        // «Səsdən çıx» or «İndi yox»: remembered on the device, and read again after a reload.
        expect(out.has(HOST)).toBe(false);
        out.leave(HOST);
        expect(out.has(HOST)).toBe(true);
        expect(stored()).toEqual([HOST]);
        expect(new VoiceOut(() => storage).has(HOST)).toBe(true);
        // «Səsə qoşul» takes the room off.
        out.back(HOST);
        expect(out.has(HOST)).toBe(false);
        expect(stored()).toEqual([]);

        // The last twenty, the oldest first out; a room left again goes to the end.
        for (let i = 1; i <= VOICE_OUT_KEEP + 1; i++) {
            out.leave(`room-${i}`);
        }
        out.leave('room-2');
        expect(VOICE_OUT_KEEP).toBe(20);
        expect(out.has('room-1')).toBe(false);
        expect(stored()).toHaveLength(20);
        expect((stored() as string[]).at(-1)).toBe('room-2');
        expect((stored() as string[])[0]).toBe('room-3');

        // The page is told of each change while it listens.
        const told = vi.fn();
        const stop = out.subscribe(told);
        out.leave('room-x');
        stop();
        out.leave('room-y');
        expect(told).toHaveBeenCalledTimes(1);

        // Whatever else the key holds is an empty list, or only its ids.
        kept.set(VOICE_OUT_KEY, '{not json');
        expect(new VoiceOut(() => storage).has('room-y')).toBe(false);
        kept.set(VOICE_OUT_KEY, JSON.stringify([1, null, '', 'room-z']));
        expect(new VoiceOut(() => storage).has('room-z')).toBe(true);

        // A storage that throws at every read and write, one that cannot be reached, or none:
        // the page remembers until a reload, and nothing throws.
        const refusing: VoiceOutStorage = {
            getItem: () => {
                throw new DOMException('denied', 'SecurityError');
            },
            setItem: () => {
                throw new DOMException('full', 'QuotaExceededError');
            },
        };
        for (const reach of [
            () => refusing,
            () => {
                throw new DOMException('denied', 'SecurityError');
            },
            () => null,
        ]) {
            const memory = new VoiceOut(reach);
            expect(memory.has(AYGUN)).toBe(false);
            expect(() => memory.leave(AYGUN)).not.toThrow();
            expect(memory.has(AYGUN)).toBe(true);
            expect(() => memory.back(AYGUN)).not.toThrow();
            expect(memory.has(AYGUN)).toBe(false);
        }
    });
});

describe('screenVoiceView', () => {
    it("gives the screen only the state's facts", () => {
        const view = screenVoiceView(on);

        // That the voice is on, and who waits or is muted — never who is there or who speaks.
        expect(view).toEqual({
            marks: { [TURAL]: 'muted', [LEYLA]: 'waiting' },
        });
        expect(Object.keys(view ?? {})).toEqual(['marks']);
        // It takes no connection at all: the same state gives the same view.
        expect(screenVoiceView.length).toBe(1);
        expect(screenVoiceView(as(on, HOST, true))).toEqual(view);
    });
});
