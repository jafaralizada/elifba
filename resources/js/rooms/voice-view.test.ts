import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from './contract';
import { myVoiceRow, screenVoiceView, voiceView } from './voice-view';
import type { VoiceConnection, VoiceLive } from './voice-view';

/*
 * What a room page draws of the live voice (docs/architecture.md §12 "Live
 * voice", "Guard tests"; docs/frontend.md rule 15 "Live voice"): from the
 * state — who may what — and, on a phone, from its own connection — who is
 * there, whose microphone is open, who speaks — named from the state's
 * players by the row's id. The screen gets the state's facts and nothing
 * else. The fixtures are C3's: tests/Fixtures/rooms/state.voice.json (a
 * player's start screen, the voice on: the host and Aygün open, Tural
 * muted, Leyla waiting) and state.voice.off.json (the host's, the voice
 * off).
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
): VoiceConnection {
    return {
        kind: 'in',
        live: { here: [], open: [], speaking: [], ...live },
        mic,
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
                reconnecting: true,
            })?.bar,
        ).toEqual({ form: 'trouble', trouble: 'reconnecting' });
        for (const trouble of [
            'unreachable',
            'dropped',
            'elsewhere',
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
