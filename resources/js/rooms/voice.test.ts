import { describe, expect, it, vi } from 'vite-plus/test';
import type { ShownPage } from './live';
import { REJOIN_MS, VoiceLink, micError } from './voice';
import type { VoiceLibrary, VoiceTicketOutcome } from './voice';
import type { VoiceConnection } from './voice-view';

/*
 * The live voice's connection (docs/architecture.md §12 "Live voice", "The
 * page (C4)", "Guard tests"; ADR 031 decisions 2, 10, 11, 26, 31, and as
 * amended 2026-11-19 decisions 35, 38 and 40) against a fake of
 * `livekit-client`'s own surface — the Room the page makes, the options it
 * passes, the microphone it captures (`createLocalAudioTrack`, the one
 * `getUserMedia`), the calls it makes on that track and on the local
 * participant — so the test sees what the page would ask of the real
 * library. The real library is never imported here: `rooms/voice.ts` is
 * its one importer (`RoomsApartTest`).
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

const TrackEvent = {
    Ended: 'ended',
    Muted: 'muted',
    Unmuted: 'unmuted',
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

/** A captured microphone: `mute()` disables it and sends silence, `stop()` releases it. */
class FakeTrack {
    static made: FakeTrack[] = [];
    /** What the library does with an ended, muted track that is unmuted: a new `getUserMedia`, nobody's tap. */
    static recaptured = 0;
    /** The browser's refusal of the next capture, by its error's name. */
    static refuse: string | null = null;
    isMuted = false;
    readonly mediaStreamTrack: {
        readyState: MediaStreamTrackState;
        enabled: boolean;
    } = { readyState: 'live', enabled: true };
    readonly listeners = new Map<string, Listener[]>();
    readonly mute = vi.fn(async () => {
        this.isMuted = true;
        this.mediaStreamTrack.enabled = false;

        return this;
    });
    readonly unmute = vi.fn(async () => {
        if (this.isMuted && this.mediaStreamTrack.readyState === 'ended') {
            FakeTrack.recaptured++;
            this.mediaStreamTrack.readyState = 'live';
        }

        this.isMuted = false;
        this.mediaStreamTrack.enabled = true;

        return this;
    });
    readonly stop = vi.fn(() => {
        this.mediaStreamTrack.readyState = 'ended';
    });

    constructor(public readonly options: unknown) {}

    on(event: string, listener: Listener): this {
        this.listeners.set(event, [
            ...(this.listeners.get(event) ?? []),
            listener,
        ]);

        return this;
    }

    /** The browser ended the capture: a call, the device gone. */
    end(): void {
        this.mediaStreamTrack.readyState = 'ended';
        for (const listener of this.listeners.get(TrackEvent.Ended) ?? []) {
            listener(this);
        }
    }

    get sending(): boolean {
        return (
            this.mediaStreamTrack.readyState === 'live' &&
            this.mediaStreamTrack.enabled
        );
    }
}

/** Every `getUserMedia` the page makes goes through here. */
const createLocalAudioTrack = vi.fn(async (options: unknown) => {
    if (FakeTrack.refuse !== null) {
        const name = FakeTrack.refuse;
        FakeTrack.refuse = null;

        throw new DOMException('refused', name);
    }

    const track = new FakeTrack(options);
    FakeTrack.made.push(track);

    return track;
});

class FakeParticipant {
    permissions: { canPublish: boolean } = { canPublish: true };
    /** The library's own capture: the page never uses it, since it keeps a track of its own. */
    readonly setMicrophoneEnabled = vi.fn(async () => {});
    readonly published = new Set<FakeTrack>();
    readonly publishTrack = vi.fn(async (track: FakeTrack) => {
        if (!this.permissions.canPublish) {
            throw new Error('insufficient permissions');
        }

        this.published.add(track);

        return { track };
    });
    readonly unpublishTrack = vi.fn(async (track: FakeTrack, stop = true) => {
        this.published.delete(track);

        if (stop) {
            track.stop();
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

    constructor(public identity: string) {}
}

class FakeRoom {
    static made: FakeRoom[] = [];
    static failConnect = 0;
    /** Whether the browser plays a voice without a tap, for the rooms made from now on. */
    static playsAtOnce = true;
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
    canPlaybackAudio = FakeRoom.playsAtOnce;
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

    /** The page's own leave, or the library's at `pagehide`: the published tracks are stopped, as the library does by default. */
    async disconnect(stopTracks = true): Promise<void> {
        this.state = 'disconnected';
        this.unpublishAll(stopTracks);
        this.fire(RoomEvent.Disconnected, DisconnectReason.CLIENT_INITIATED);
    }

    private unpublishAll(stop: boolean): void {
        for (const track of this.localParticipant.published) {
            if (stop) {
                track.stop();
            }
        }

        this.localParticipant.published.clear();
    }

    fire(event: string, ...args: unknown[]): void {
        for (const listener of this.listeners.get(event) ?? []) {
            listener(...args);
        }
    }

    /** The voice server closed the voice room under the page: its tracks unpublished, kept (`stopLocalTrackOnUnpublish: false`). */
    closedUnder(reason: number = DisconnectReason.ROOM_DELETED): void {
        this.state = 'disconnected';
        this.unpublishAll(this.options.stopLocalTrackOnUnpublish !== false);
        this.fire(RoomEvent.Disconnected, reason);
    }

    /** The host's mute: the voice server takes the permission to publish and unpublishes the microphone. */
    mutedByHost(): void {
        const local = this.localParticipant;
        local.permissions = { canPublish: false };
        this.unpublishAll(this.options.stopLocalTrackOnUnpublish !== false);
        this.fire(
            RoomEvent.ParticipantPermissionsChanged,
            { canPublish: true },
            local,
        );
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
    TrackEvent,
    createLocalAudioTrack,
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
    FakeRoom.playsAtOnce = true;
    FakeTrack.made = [];
    FakeTrack.refuse = null;
    FakeTrack.recaptured = 0;
    createLocalAudioTrack.mockClear();
    const seen: VoiceConnection[] = [];
    const asked: boolean[] = [];
    const hiddenMic = vi.fn();
    const agreement = vi.fn();
    const audio: boolean[] = [];
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
        onAudio: (playing) => audio.push(playing),
        page: visibility.page,
        audio: { append: (element) => appended.push(element) },
        wait: async (ms) => {
            waits.push(ms);
        },
    });
    const room = () => FakeRoom.made[FakeRoom.made.length - 1];
    const track = () => FakeTrack.made[FakeTrack.made.length - 1];

    return {
        link,
        track,
        audio,
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
        // The room's own options carry no ICE list, no encryption and ADR 031 decision 31's audio;
        // a closed microphone is a kept, disabled track (decision 35, as amended 2026-11-19).
        expect(room().options).toEqual({
            adaptiveStream: false,
            dynacast: false,
            disconnectOnPageLeave: true,
            stopLocalTrackOnUnpublish: false,
            audioCaptureDefaults: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true,
            },
            publishDefaults: {
                audioPreset: { maxBitrate: 24_000 },
                dtx: true,
                red: true,
                stopMicTrackOnMute: false,
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

        // Joining — by itself or on a tap — listens and captures nothing: no browser question.
        await link.join(false);
        expect(createLocalAudioTrack).not.toHaveBeenCalled();
        expect(seen.at(-1)).toMatchObject({
            kind: 'in',
            mic: 'off',
            captured: false,
        });

        // A join after a leave starts off again, though the microphone was open before.
        await link.setMic(true);
        expect(seen.at(-1)).toMatchObject({ kind: 'in', mic: 'on' });
        await link.leave();
        await link.join(false);
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(1);
        expect(seen.at(-1)).toMatchObject({
            kind: 'in',
            mic: 'off',
            captured: false,
        });
        // Only its owner's tap opens it, and the browser's question comes with that tap.
        await link.setMic(true);
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(2);
        // The library's own capture is never asked for.
        expect(
            room().localParticipant.setMicrophoneEnabled,
        ).not.toHaveBeenCalled();
    });

    it('captures the microphone at the first open and never again until the voice is left', async () => {
        const { link, seen, room, track } = setup([ticket(1), ticket(2)]);

        await link.join(true);
        await link.setMic(true);
        expect(createLocalAudioTrack.mock.calls).toEqual([
            [
                {
                    echoCancellation: true,
                    noiseSuppression: true,
                    autoGainControl: true,
                },
            ],
        ]);
        const mic = track();
        expect(room().localParticipant.published).toEqual(new Set([mic]));
        expect(mic.sending).toBe(true);
        expect(seen.at(-1)).toMatchObject({ mic: 'on', captured: true });

        // Close, open, close, open: the same capture, enabled and disabled — no new question.
        for (let i = 0; i < 3; i++) {
            await link.setMic(false);
            expect(seen.at(-1)).toMatchObject({ mic: 'off', captured: true });
            await link.setMic(true);
            expect(seen.at(-1)).toMatchObject({ mic: 'on', captured: true });
        }

        expect(createLocalAudioTrack).toHaveBeenCalledTimes(1);
        expect(mic.stop).not.toHaveBeenCalled();
        expect(room().localParticipant.publishTrack).toHaveBeenCalledTimes(1);

        // Left: released. Back in: the next open captures again.
        await link.leave();
        expect(mic.stop).toHaveBeenCalled();
        await link.join(false);
        await link.setMic(true);
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(2);
        expect(track()).not.toBe(mic);
    });

    it('opens the microphone in the tap that brings the page into the voice', async () => {
        const { link, seen, asked, room } = setup();

        // Out of the voice, the microphone's tap (decision 45): in, and the microphone open, one capture.
        await link.join(false, true);
        expect(asked).toEqual([false]);
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(1);
        expect(room().localParticipant.published.size).toBe(1);
        expect(seen.at(-1)).toMatchObject({
            kind: 'in',
            mic: 'on',
            captured: true,
        });

        // A refused ticket captures nothing.
        const refused = setup([{ kind: 'refused' }]);
        await refused.link.join(false, true);
        expect(createLocalAudioTrack).not.toHaveBeenCalled();
        expect(refused.seen.at(-1)).toEqual({ kind: 'idle' });
    });

    it('closes the microphone by disabling its track, sending no sound', async () => {
        const { link, seen, room, track } = setup();

        await link.join(false);
        await link.setMic(true);
        await link.setMic(false);

        // Disabled — silence, and the voice server told it is muted — but kept and still published.
        const mic = track();
        expect(mic.mute).toHaveBeenCalledTimes(1);
        expect(mic.isMuted).toBe(true);
        expect(mic.mediaStreamTrack.enabled).toBe(false);
        expect(mic.sending).toBe(false);
        expect(mic.mediaStreamTrack.readyState).toBe('live');
        expect(mic.stop).not.toHaveBeenCalled();
        expect(room().localParticipant.published).toEqual(new Set([mic]));
        expect(seen.at(-1)).toMatchObject({
            kind: 'in',
            mic: 'off',
            captured: true,
        });
    });

    it("releases the microphone at «Səsdən çıx», a cut, the voice off and the host's mute", async () => {
        // «Səsdən çıx».
        const leaving = setup();
        await leaving.link.join(false);
        await leaving.link.setMic(true);
        await leaving.link.setMic(false);
        await leaving.link.leave();
        expect(leaving.track().stop).toHaveBeenCalled();
        expect(leaving.room().localParticipant.published.size).toBe(0);

        // A removal or the voice off: the voice room is closed under the page and the next ticket refused.
        const cut = setup([ticket(1), { kind: 'refused' }]);
        await cut.link.join(false);
        await cut.link.setMic(true);
        cut.room().closedUnder();
        for (let i = 0; i < 4; i++) {
            await settle();
        }
        expect(cut.seen.at(-1)).toEqual({ kind: 'idle' });
        expect(cut.track().stop).toHaveBeenCalled();

        // The voice server gone for good: a failure releases it too.
        const failed = setup([ticket(1), { kind: 'unavailable' }]);
        await failed.link.join(false);
        await failed.link.setMic(true);
        failed.room().closedUnder();
        for (let i = 0; i < 10; i++) {
            await settle();
        }
        expect(failed.seen.at(-1)).toEqual({
            kind: 'failed',
            trouble: 'unreachable',
        });
        expect(failed.track().stop).toHaveBeenCalled();

        // The host's mute: released, and «Danışmağa icazə ver» opens nothing.
        const muted = setup();
        await muted.link.join(false);
        await muted.link.setMic(true);
        const mic = muted.track();
        muted.room().mutedByHost();
        expect(mic.stop).toHaveBeenCalled();
        expect(muted.seen.at(-1)).toMatchObject({
            mic: 'off',
            captured: false,
        });
        const local = muted.room().localParticipant;
        local.permissions = { canPublish: true };
        muted
            .room()
            .fire(
                RoomEvent.ParticipantPermissionsChanged,
                { canPublish: false },
                local,
            );
        expect(muted.seen.at(-1)).toMatchObject({ mic: 'off' });
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(1);

        // The browser ends the capture (a call): closed first, then released; the next open captures again.
        const ended = setup();
        await ended.link.join(false);
        await ended.link.setMic(true);
        const first = ended.track();
        first.end();
        expect(first.mute).toHaveBeenCalled();
        await settle();
        expect(first.stop).toHaveBeenCalled();
        expect(ended.seen.at(-1)).toMatchObject({
            mic: 'off',
            captured: false,
        });
        await ended.link.setMic(true);
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(2);
        expect(ended.seen.at(-1)).toMatchObject({ mic: 'on', captured: true });
    });

    it('never captures again by itself when the browser ends the capture while it is published', async () => {
        const { link, seen, room, track } = setup();

        await link.join(false);
        // The browser grants, then ends the capture at once (a phone call; the iOS Simulator, which has no microphone).
        room().localParticipant.publishTrack.mockImplementationOnce(
            async (published: FakeTrack) => {
                published.end();
                await settle();

                return { track: published };
            },
        );
        await link.setMic(true);
        await settle();

        expect(createLocalAudioTrack).toHaveBeenCalledTimes(1);
        expect(FakeTrack.recaptured).toBe(0);
        expect(track().stop).toHaveBeenCalled();
        expect(seen.at(-1)).toMatchObject({ mic: 'off', captured: false });

        // The owner's next tap captures again: the one way back.
        await link.setMic(true);
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(2);
        expect(seen.at(-1)).toMatchObject({ mic: 'on', captured: true });
    });

    it('publishes the kept microphone again in a voice room closed under the page, closed unless it was open', async () => {
        const { link, seen, room, track } = setup([
            ticket(1),
            ticket(2),
            ticket(3),
        ]);

        await link.join(true);
        await link.setMic(true);
        const mic = track();

        // Open a moment before: open again in the new voice room, the same capture.
        room().closedUnder();
        for (let i = 0; i < 4; i++) {
            await settle();
        }
        expect(FakeRoom.made).toHaveLength(2);
        expect(room().localParticipant.published).toEqual(new Set([mic]));
        expect(mic.sending).toBe(true);
        expect(seen.at(-1)).toMatchObject({
            kind: 'in',
            mic: 'on',
            captured: true,
            reconnecting: false,
        });

        // Closed a moment before: published again, closed.
        await link.setMic(false);
        room().closedUnder();
        for (let i = 0; i < 4; i++) {
            await settle();
        }
        expect(FakeRoom.made).toHaveLength(3);
        expect(room().localParticipant.published).toEqual(new Set([mic]));
        expect(mic.sending).toBe(false);
        expect(seen.at(-1)).toMatchObject({
            kind: 'in',
            mic: 'off',
            captured: true,
            reconnecting: false,
        });
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(1);
        expect(mic.stop).not.toHaveBeenCalled();
    });

    it('turns its microphone off when the page is hidden', async () => {
        const { link, seen, track, visibility, hiddenMic } = setup();

        await link.join(false);
        await link.setMic(true);
        const mic = track();
        const opened = mic.unmute.mock.calls.length;

        visibility.set('hidden');
        // At once, in the same turn: no open microphone on a page nobody sees.
        expect(mic.mute).toHaveBeenCalledTimes(1);
        expect(mic.sending).toBe(false);
        expect(seen.at(-1)).toMatchObject({ kind: 'in', mic: 'off' });
        expect(hiddenMic).toHaveBeenCalledTimes(1);

        // Shown again: still listening, the microphone still off until its owner opens it.
        visibility.set('visible');
        await settle();
        expect(mic.unmute).toHaveBeenCalledTimes(opened);
        expect(mic.sending).toBe(false);
        expect(seen.at(-1)).toMatchObject({
            kind: 'in',
            mic: 'off',
            reconnecting: false,
        });
        expect(FakeRoom.made).toHaveLength(1);
    });

    it('keeps the microphone closed and captured while the page is hidden', async () => {
        const { link, seen, track, visibility } = setup();

        await link.join(false);
        await link.setMic(true);
        const mic = track();
        visibility.set('hidden');
        visibility.set('visible');
        await settle();

        // Disabled, not stopped: opening it again is no new question.
        expect(mic.stop).not.toHaveBeenCalled();
        expect(seen.at(-1)).toMatchObject({ mic: 'off', captured: true });
        await link.setMic(true);
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(1);
        expect(mic.sending).toBe(true);

        // The library left with the page (`pagehide`) and stopped the capture: back in listening, captured anew on a tap.
        visibility.set('hidden');
        const room = FakeRoom.made[0];
        await room.disconnect();
        await settle();
        visibility.set('visible');
        for (let i = 0; i < 4; i++) {
            await settle();
        }
        expect(seen.at(-1)).toMatchObject({
            kind: 'in',
            mic: 'off',
            captured: false,
            reconnecting: false,
        });
        await link.setMic(true);
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(2);
    });

    it('tells the page when the library leaves with it while the microphone is open', async () => {
        const leftOpen = vi.fn();
        FakeRoom.made = [];
        const link = new VoiceLink({
            load: async () => lib,
            ticket: async () => ticket(1),
            onChange: () => {},
            onLeftOpen: leftOpen,
            page: fakePage().page,
            audio: { append: () => {} },
            wait: async () => {},
        });

        // A reload: the library leaves at `beforeunload` with the microphone open (decision 46).
        await link.join(false, true);
        await FakeRoom.made[0].disconnect();
        await settle();
        expect(leftOpen).toHaveBeenCalledTimes(1);

        // Closed before, or the player's own «Səsdən çıx»: nothing to carry over.
        await link.join(false);
        await FakeRoom.made[1].disconnect();
        await settle();
        await link.join(false, true);
        await link.leave();
        expect(leftOpen).toHaveBeenCalledTimes(1);
    });

    it('asks for a new ticket when the voice room is closed under it, and stops after the last try', async () => {
        const unavailable: VoiceTicketOutcome = { kind: 'unavailable' };
        const { link, seen, asked, room, track, waits } = setup([
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
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(1);
        expect(track().sending).toBe(true);
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

    it('says the voice is full and does not try again by itself', async () => {
        // The first join: busy, and nothing more until a tap.
        const full = setup([{ kind: 'busy' }, ticket(1)]);
        await full.link.join(false);
        for (let i = 0; i < 5; i++) {
            await settle();
        }
        expect(full.seen.at(-1)).toEqual({ kind: 'failed', trouble: 'busy' });
        expect(full.asked).toEqual([false]);
        expect(FakeRoom.made).toHaveLength(0);
        // «Yenidən cəhd et»: one more try, on the tap.
        await full.link.join(false);
        expect(full.asked).toEqual([false, false]);
        expect(full.seen.at(-1)).toMatchObject({ kind: 'in' });

        // A voice room closed under the page that comes back full: one ask, no knocking.
        const later = setup([ticket(1), { kind: 'busy' }]);
        await later.link.join(false);
        await later.link.setMic(true);
        later.room().closedUnder();
        for (let i = 0; i < 10; i++) {
            await settle();
        }
        expect(later.asked).toEqual([false, false]);
        expect(later.waits).toEqual([0]);
        expect(later.seen.at(-1)).toEqual({ kind: 'failed', trouble: 'busy' });
        expect(later.track().stop).toHaveBeenCalled();
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
        // The host mutes: the voice server takes the permission and the library unpublishes.
        room().mutedByHost();
        expect(seen.at(-1)).toMatchObject({ mic: 'off' });
        // The host lets them speak again: the permission comes back, the microphone stays off.
        const local = room().localParticipant;
        local.permissions = { canPublish: true };
        room().fire(
            RoomEvent.ParticipantPermissionsChanged,
            { canPublish: false },
            local,
        );
        expect(seen.at(-1)).toMatchObject({ mic: 'off' });
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(1);
        // And a voice room closed under it now brings it back closed, capturing nothing.
        room().closedUnder();
        await settle();
        await settle();
        expect(room().localParticipant.published.size).toBe(0);
        expect(createLocalAudioTrack).toHaveBeenCalledTimes(1);
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

    it('asks for its ticket while the library is still loading, and says so when the library never comes', async () => {
        FakeRoom.made = [];
        const asked: boolean[] = [];
        const seen: VoiceConnection[] = [];
        const make = (load: () => Promise<VoiceLibrary>) =>
            new VoiceLink({
                load,
                ticket: async (agree) => {
                    asked.push(agree);

                    return ticket(1);
                },
                onChange: (connection) => seen.push(connection),
                page: fakePage().page,
                audio: { append: () => {} },
                wait: async () => {},
            });
        let release: (library: VoiceLibrary) => void = () => {};
        const link = make(
            () =>
                new Promise((resolve) => {
                    release = resolve;
                }),
        );

        const joining = link.join(true);
        await settle();
        // On a phone's network both are round trips (C5): neither waits for the other.
        expect(asked).toEqual([true]);
        expect(FakeRoom.made).toHaveLength(0);
        release(lib);
        await joining;
        expect(FakeRoom.made).toHaveLength(1);
        expect(seen.at(-1)).toMatchObject({ kind: 'in' });

        let refuse: (error: Error) => void = () => {};
        const failing = make(
            () =>
                new Promise((_resolve, reject) => {
                    refuse = reject;
                }),
        );
        const failed = failing.join(false);
        await settle();
        expect(asked).toEqual([true, false]);
        refuse(new Error('the chunk did not load'));
        await failed;
        expect(seen.at(-1)).toEqual({ kind: 'failed', trouble: 'unreachable' });
        expect(FakeRoom.made).toHaveLength(1);
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
        FakeTrack.refuse = 'NotAllowedError';
        await refused.setMic(true);
        expect(errors).toEqual(['denied']);
        // Refused, so nothing is kept: the next tap asks again.
        expect(refused.current).toMatchObject({ mic: 'off', captured: false });
    });

    it('says when the browser holds the voice back, and plays it from a tap', async () => {
        const { link, room, audio } = setup();
        // The browser would not play without a tap (an iPhone, or a page nobody tapped).
        FakeRoom.playsAtOnce = false;

        await link.join(false);
        expect(audio).toEqual([false]);
        expect(room().startAudio).toHaveBeenCalledTimes(1);

        // A tap anywhere: the page asks the library to start every voice.
        link.unlockAudio();
        expect(room().startAudio).toHaveBeenCalledTimes(2);
        room().canPlaybackAudio = true;
        room().fire(RoomEvent.AudioPlaybackStatusChanged);
        expect(audio).toEqual([false, true]);
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
