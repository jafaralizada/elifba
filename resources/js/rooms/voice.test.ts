import { describe, expect, it, vi } from 'vite-plus/test';
import type { ShownPage } from './live';
import { REJOIN_MS, VoiceLink, micError } from './voice';
import type { VoiceLibrary, VoiceTicketOutcome } from './voice';
import type { VoiceConnection } from './voice-view';

/*
 * The live voice's connection (docs/architecture.md §12 "Live voice", "The
 * page (C4)", "Guard tests"; ADR 031 decisions 2, 10, 11, 26, 31) against a
 * fake of `livekit-client`'s own surface — the Room the page makes, the
 * options it passes, the calls it makes on the local participant — so the
 * test sees what the page would ask of the real library. The real library
 * is never imported here: `rooms/voice.ts` is its one importer
 * (`RoomsApartTest`).
 */

const RoomEvent = {
    Connected: 'connected',
    Reconnecting: 'reconnecting',
    SignalReconnecting: 'signalReconnecting',
    Reconnected: 'reconnected',
    Disconnected: 'disconnected',
    ParticipantConnected: 'participantConnected',
    ParticipantDisconnected: 'participantDisconnected',
    TrackPublished: 'trackPublished',
    TrackSubscribed: 'trackSubscribed',
    TrackUnpublished: 'trackUnpublished',
    TrackUnsubscribed: 'trackUnsubscribed',
    TrackMuted: 'trackMuted',
    TrackUnmuted: 'trackUnmuted',
    LocalTrackPublished: 'localTrackPublished',
    LocalTrackUnpublished: 'localTrackUnpublished',
    ActiveSpeakersChanged: 'activeSpeakersChanged',
    AudioPlaybackStatusChanged: 'audioPlaybackChanged',
    MediaDevicesError: 'mediaDevicesError',
    ParticipantPermissionsChanged: 'participantPermissionsChanged',
    DataReceived: 'dataReceived',
} as const;

const DisconnectReason = {
    UNKNOWN_REASON: 0,
    CLIENT_INITIATED: 1,
    DUPLICATE_IDENTITY: 2,
    SERVER_SHUTDOWN: 3,
    PARTICIPANT_REMOVED: 4,
    ROOM_DELETED: 5,
    STATE_MISMATCH: 6,
    JOIN_FAILURE: 7,
    MIGRATION: 8,
    SIGNAL_CLOSE: 9,
    ROOM_CLOSED: 10,
} as const;

type Listener = (...args: unknown[]) => void;

class FakeParticipant {
    permissions: { canPublish: boolean } = { canPublish: true };
    readonly setMicrophoneEnabled = vi.fn(async (on: boolean) => {
        if (on && this.refuse !== null) {
            throw new DOMException('refused', this.refuse);
        }
    });
    // Every way the library could send a data message: none may be called.
    readonly publishData = vi.fn();
    readonly sendText = vi.fn();
    readonly sendChatMessage = vi.fn();
    readonly publishDtmf = vi.fn();
    readonly sendFile = vi.fn();
    readonly streamText = vi.fn();
    readonly streamBytes = vi.fn();
    readonly performRpc = vi.fn();
    readonly setMetadata = vi.fn();
    readonly setName = vi.fn();
    readonly setAttributes = vi.fn();
    refuse: string | null = null;

    constructor(public identity: string) {}
}

class FakeRoom {
    static made: FakeRoom[] = [];
    static failConnect = 0;
    readonly listeners = new Map<string, Listener[]>();
    /** Every event the page ever listened for. */
    readonly registered = new Set<string>();
    readonly connects: { url: string; token: string; options: unknown }[] = [];
    state = 'disconnected';
    localParticipant = new FakeParticipant('');
    remoteParticipants = new Map<
        string,
        { identity: string; isMicrophoneEnabled: boolean }
    >();
    activeSpeakers: { identity: string }[] = [];
    canPlaybackAudio = true;
    readonly startAudio = vi.fn(async () => {});

    constructor(public readonly options: Record<string, unknown>) {
        FakeRoom.made.push(this);
    }

    on(event: string, listener: Listener): this {
        this.registered.add(event);
        this.listeners.set(event, [
            ...(this.listeners.get(event) ?? []),
            listener,
        ]);

        return this;
    }

    removeAllListeners(): this {
        this.listeners.clear();

        return this;
    }

    async connect(url: string, token: string, options: unknown): Promise<void> {
        this.connects.push({ url, token, options });

        if (FakeRoom.failConnect > 0) {
            FakeRoom.failConnect--;
            throw new Error('could not establish signal connection');
        }

        this.state = 'connected';
        this.localParticipant.identity = token.replace('ticket-', '');
    }

    async disconnect(): Promise<void> {
        this.state = 'disconnected';
        this.fire(RoomEvent.Disconnected, DisconnectReason.CLIENT_INITIATED);
    }

    fire(event: string, ...args: unknown[]): void {
        for (const listener of this.listeners.get(event) ?? []) {
            listener(...args);
        }
    }

    /** The voice server closed the voice room under the page. */
    closedUnder(reason: number = DisconnectReason.ROOM_DELETED): void {
        this.state = 'disconnected';
        this.fire(RoomEvent.Disconnected, reason);
    }
}

const lib = {
    Room: FakeRoom,
    RoomEvent,
    DisconnectReason,
    ConnectionState: {
        Disconnected: 'disconnected',
        Connecting: 'connecting',
        Connected: 'connected',
        Reconnecting: 'reconnecting',
        SignalReconnecting: 'signalReconnecting',
    },
    AudioPresets: {
        speech: { maxBitrate: 24_000 },
        music: { maxBitrate: 48_000 },
    },
    Track: { Kind: { Audio: 'audio', Video: 'video', Unknown: 'unknown' } },
} as unknown as VoiceLibrary;

function fakePage() {
    let visibility: DocumentVisibilityState = 'visible';
    const listeners = new Set<() => void>();
    const page: ShownPage = {
        get visibilityState() {
            return visibility;
        },
        addEventListener: (_type, listener) => listeners.add(listener),
        removeEventListener: (_type, listener) => listeners.delete(listener),
    };

    return {
        page,
        set(next: DocumentVisibilityState) {
            visibility = next;
            listeners.forEach((listener) => listener());
        },
    };
}

/** A link on the fake library; tickets are handed out from `tickets`, then the last one again. */
function setup(tickets: VoiceTicketOutcome[] = [ticket(1)]) {
    FakeRoom.made = [];
    FakeRoom.failConnect = 0;
    const seen: VoiceConnection[] = [];
    const asked: boolean[] = [];
    const hiddenMic = vi.fn();
    const agreement = vi.fn();
    const visibility = fakePage();
    const appended: unknown[] = [];
    const waits: number[] = [];
    const link = new VoiceLink({
        load: async () => lib,
        ticket: async (agree) => {
            asked.push(agree);

            return tickets.length > 1
                ? (tickets.shift() as VoiceTicketOutcome)
                : tickets[0];
        },
        onChange: (connection) => seen.push(connection),
        onAgreement: agreement,
        onHiddenMic: hiddenMic,
        page: visibility.page,
        audio: { append: (element) => appended.push(element) },
        wait: async (ms) => {
            waits.push(ms);
        },
    });
    const room = () => FakeRoom.made[FakeRoom.made.length - 1];

    return {
        link,
        seen,
        asked,
        hiddenMic,
        agreement,
        visibility,
        appended,
        waits,
        room,
    };
}

function ticket(n: number): VoiceTicketOutcome {
    return {
        kind: 'ticket',
        ticket: {
            url: 'wss://elifba.example/',
            token: `ticket-01kaqrx3mn4p5q6r7s8t9vwx0${n}`,
        },
    };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('VoiceLink', () => {
    it("connects with no ICE server, its own or the server's", async () => {
        const { link, room } = setup([ticket(1), ticket(2)]);

        await link.join(true);
        expect(room().connects).toHaveLength(1);
        expect(room().connects[0].options).toEqual({
            autoSubscribe: true,
            rtcConfig: { iceServers: [] },
        });
        // The room's own options carry no ICE list, no encryption and ADR 031 decision 31's audio.
        expect(room().options).toEqual({
            adaptiveStream: false,
            dynacast: false,
            disconnectOnPageLeave: true,
            audioCaptureDefaults: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
            publishDefaults: {
                audioPreset: { maxBitrate: 24_000 },
                dtx: true,
                red: true,
                stopMicTrackOnMute: true,
            },
        });

        // And again when it comes back into a voice room closed under it.
        room().closedUnder();
        await settle();
        expect(FakeRoom.made).toHaveLength(2);
        expect(room().connects[0].options).toEqual({
            autoSubscribe: true,
            rtcConfig: { iceServers: [] },
        });
    });

    it('opens no microphone at a join', async () => {
        const { link, seen, room } = setup();

        await link.join(false);
        expect(
            room().localParticipant.setMicrophoneEnabled,
        ).not.toHaveBeenCalled();
        expect(seen.at(-1)).toMatchObject({ kind: 'in', mic: 'off' });

        // A join after a leave starts off again, though the microphone was open before.
        await link.setMic(true);
        expect(seen.at(-1)).toMatchObject({ kind: 'in', mic: 'on' });
        await link.leave();
        await link.join(false);
        expect(
            room().localParticipant.setMicrophoneEnabled,
        ).not.toHaveBeenCalled();
        expect(seen.at(-1)).toMatchObject({ kind: 'in', mic: 'off' });
        // Only its owner's tap opens it, and the browser's question comes with that tap.
        await link.setMic(true);
        expect(room().localParticipant.setMicrophoneEnabled.mock.calls).toEqual(
            [[true]],
        );
    });

    it('turns its microphone off when the page is hidden', async () => {
        const { link, seen, room, visibility, hiddenMic } = setup();

        await link.join(false);
        await link.setMic(true);
        const microphone = room().localParticipant.setMicrophoneEnabled;
        expect(microphone.mock.calls).toEqual([[true]]);

        visibility.set('hidden');
        // At once, in the same turn: no open microphone on a page nobody sees.
        expect(microphone.mock.calls).toEqual([[true], [false]]);
        expect(seen.at(-1)).toMatchObject({ kind: 'in', mic: 'off' });
        expect(hiddenMic).toHaveBeenCalledTimes(1);

        // Shown again: still listening, the microphone still off until its owner opens it.
        visibility.set('visible');
        await settle();
        expect(microphone.mock.calls).toEqual([[true], [false]]);
        expect(seen.at(-1)).toMatchObject({
            kind: 'in',
            mic: 'off',
            reconnecting: false,
        });
        expect(FakeRoom.made).toHaveLength(1);
    });

    it('asks for a new ticket when the voice room is closed under it, and stops after the last try', async () => {
        const unavailable: VoiceTicketOutcome = { kind: 'unavailable' };
        const { link, seen, asked, room, waits } = setup([
            ticket(1),
            ticket(2),
            unavailable,
        ]);

        await link.join(true);
        await link.setMic(true);
        // A removal elsewhere raised the epoch: a new ticket, the same row, the microphone as it was.
        room().closedUnder();
        await settle();
        await settle();
        expect(asked).toEqual([true, false]);
        expect(FakeRoom.made).toHaveLength(2);
        expect(room().localParticipant.setMicrophoneEnabled.mock.calls).toEqual(
            [[true]],
        );
        expect(seen.at(-1)).toMatchObject({
            kind: 'in',
            mic: 'on',
            reconnecting: false,
        });

        // The voice server gone too: a few tries, then «Canlı səsə qoşulmaq alınmadı» and no more asking.
        room().closedUnder();
        for (let i = 0; i < 10; i++) {
            await settle();
        }
        expect(asked).toEqual([true, false, ...REJOIN_MS.map(() => false)]);
        expect(waits.slice(-REJOIN_MS.length)).toEqual([...REJOIN_MS]);
        expect(seen.at(-1)).toEqual({ kind: 'failed', trouble: 'unreachable' });
        expect(FakeRoom.made).toHaveLength(2);
    });

    it('stops at a refused ticket and leaves the page out of the voice', async () => {
        const { link, seen, asked, room } = setup([
            ticket(1),
            { kind: 'refused' },
        ]);

        await link.join(false);
        // The host turned the voice off, or removed this player: the state says which.
        room().closedUnder();
        await settle();
        await settle();
        expect(asked).toEqual([false, false]);
        expect(seen.at(-1)).toEqual({ kind: 'idle' });
    });

    it("never reopens a microphone the host closed, nor on the host's word", async () => {
        const { link, seen, room } = setup();

        await link.join(false);
        await link.setMic(true);
        const local = room().localParticipant;
        // The host mutes: the voice server takes the permission and the library unpublishes.
        local.permissions = { canPublish: false };
        room().fire(
            RoomEvent.ParticipantPermissionsChanged,
            { canPublish: true },
            local,
        );
        expect(seen.at(-1)).toMatchObject({ mic: 'off' });
        // The host lets them speak again: the permission comes back, the microphone stays off.
        local.permissions = { canPublish: true };
        room().fire(
            RoomEvent.ParticipantPermissionsChanged,
            { canPublish: false },
            local,
        );
        expect(seen.at(-1)).toMatchObject({ mic: 'off' });
        expect(local.setMicrophoneEnabled.mock.calls).toEqual([[true]]);
        // And a voice room closed under it now brings it back closed.
        room().closedUnder();
        await settle();
        await settle();
        expect(
            room().localParticipant.setMicrophoneEnabled,
        ).not.toHaveBeenCalled();
    });

    it('says when another device of the account took the voice, and joins here again on a tap', async () => {
        const { link, seen, room } = setup([ticket(1), ticket(1)]);

        await link.join(false);
        room().closedUnder(DisconnectReason.DUPLICATE_IDENTITY);
        await settle();
        expect(seen.at(-1)).toEqual({ kind: 'failed', trouble: 'elsewhere' });
        expect(FakeRoom.made).toHaveLength(1);
        await link.join(false);
        expect(seen.at(-1)).toMatchObject({ kind: 'in' });
    });

    it('asks for the sentence again when the agreement is missing, and says when the server is not there', async () => {
        const first = setup([{ kind: 'agreement' }]);
        await first.link.join(false);
        expect(first.agreement).toHaveBeenCalledTimes(1);
        expect(first.seen.at(-1)).toEqual({ kind: 'idle' });
        expect(FakeRoom.made).toHaveLength(0);

        const second = setup([{ kind: 'unavailable' }]);
        await second.link.join(true);
        expect(second.seen.at(-1)).toEqual({
            kind: 'failed',
            trouble: 'unreachable',
        });

        const third = setup();
        FakeRoom.failConnect = 1;
        await third.link.join(true);
        expect(third.seen.at(-1)).toEqual({
            kind: 'failed',
            trouble: 'unreachable',
        });
    });

    it('says a dropped connection, and a refused or missing microphone in words', async () => {
        const { link, seen, room } = setup();
        const errors: string[] = [];

        await link.join(false);
        room().state = 'reconnecting';
        room().fire(RoomEvent.Reconnecting);
        expect(seen.at(-1)).toMatchObject({ kind: 'in', reconnecting: true });
        room().closedUnder(DisconnectReason.SIGNAL_CLOSE);
        await settle();
        expect(seen.at(-1)).toEqual({ kind: 'failed', trouble: 'dropped' });

        expect(micError(new DOMException('no', 'NotAllowedError'))).toBe(
            'denied',
        );
        expect(micError(new DOMException('no', 'NotFoundError'))).toBe(
            'no_mic',
        );
        const refused = new VoiceLink({
            load: async () => lib,
            ticket: async () => ticket(3),
            onChange: () => {},
            onMicError: (error) => errors.push(error),
            page: fakePage().page,
            audio: { append: () => {} },
        });
        await refused.join(false);
        FakeRoom.made.at(-1)!.localParticipant.refuse = 'NotAllowedError';
        await refused.setMic(true);
        expect(errors).toEqual(['denied']);
    });

    it('hears every voice through an element of its own, and lets it go', async () => {
        const { link, room, appended } = setup();
        const element = { hidden: false, remove: vi.fn() };
        const track = {
            kind: 'audio',
            attach: vi.fn(() => element),
            detach: vi.fn(() => [element]),
        };

        await link.join(false);
        room().fire(RoomEvent.TrackSubscribed, track);
        expect(appended).toEqual([element]);
        expect(element.hidden).toBe(true);
        room().fire(RoomEvent.TrackUnsubscribed, track);
        expect(element.remove).toHaveBeenCalled();
    });

    it('sends no data message', async () => {
        const { link, room, visibility } = setup([ticket(1), ticket(2)]);

        await link.join(true);
        await link.setMic(true);
        room().remoteParticipants.set('01kaqrx2ab3c4d5e6f7g8h9jkm', {
            identity: '01kaqrx2ab3c4d5e6f7g8h9jkm',
            isMicrophoneEnabled: true,
        });
        room().fire(RoomEvent.ParticipantConnected);
        room().fire(RoomEvent.DataReceived, new Uint8Array([1]));
        visibility.set('hidden');
        visibility.set('visible');
        room().closedUnder();
        await settle();
        await link.setMic(true);
        await link.leave();

        for (const made of FakeRoom.made) {
            const local = made.localParticipant;
            for (const send of [
                local.publishData,
                local.sendText,
                local.sendChatMessage,
                local.publishDtmf,
                local.sendFile,
                local.streamText,
                local.streamBytes,
                local.performRpc,
                local.setMetadata,
                local.setName,
                local.setAttributes,
            ]) {
                expect(send).not.toHaveBeenCalled();
            }
            // It does not even listen for one.
            expect(made.registered.has(RoomEvent.DataReceived)).toBe(false);
        }
    });
});
