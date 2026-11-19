/**
 * The live voice's connection (docs/architecture.md §12 "Live voice", "The
 * page (C4)"; ADR 031 decisions 1, 2, 5, 10, 11, 26 and 31, and as amended
 * 2026-11-19 decisions 35, 38 and 40) — the one file that imports
 * `livekit-client`, and only when the page first joins the voice — by
 * itself for an admitted player whose account agreed, or on the tap of
 * «Səsə qoşul» (`loadVoiceLibrary()`) — so the library is a chunk of its
 * own from the app's host and no other page loads it.
 *
 * A join asks the app for a ticket (`POST /rooms/{room}/voice/tickets`)
 * and connects to the instance's own voice server with an empty list of
 * ICE servers, always: neither a public STUN server nor the voice server's
 * own list is ever used. A join captures nothing: the microphone is off at
 * every join, and only its owner's tap opens it (`setMic()`). The page owns
 * one capture of it, made at the owner's first «Mikrofonu aç» — the
 * browser's one question on the page, where it asks at all — and kept for
 * the whole voice: closing it disables the track, which sends silence and
 * tells the voice server it is muted, and opening it again enables the
 * same track, with no new `getUserMedia` and so no question. It is
 * released — stopped, and the device's indicator goes out — when the page
 * leaves the voice («Səsdən çıx», the voice off, a removal, a close, a
 * failure, the page left), at the host's mute and when the browser ends
 * it. A hidden page closes its own microphone at once, keeping the
 * capture, and says so when shown; when the voice room is closed under the
 * page (a new epoch: a removal, the sweep), the page asks for a new ticket
 * and comes back by itself, publishing the kept microphone again, open
 * only if it was open a moment before, and stops after the last try. A
 * full instance (`busy`) waits for a tap. The page never sends a data
 * message, and what the connection says — who is there, whose microphone
 * is open, who speaks — is reported as `VoiceConnection` for `voiceView()`
 * to draw and is stored nowhere.
 */
import type * as LiveKit from 'livekit-client';
import { z } from 'zod';
import type { ShownPage } from './live';
import type { VoiceConnection, VoiceLive, VoiceTrouble } from './voice-view';

/** The parts of `livekit-client` this file uses. */
export type VoiceLibrary = Pick<
    typeof LiveKit,
    | 'Room'
    | 'RoomEvent'
    | 'DisconnectReason'
    | 'ConnectionState'
    | 'AudioPresets'
    | 'Track'
    | 'TrackEvent'
    | 'createLocalAudioTrack'
>;

/** The library, as its own chunk: imported when the page first joins the voice, never before. */
export function loadVoiceLibrary(): Promise<VoiceLibrary> {
    return import('livekit-client');
}

/** `{url, token}`: where the voice server is and the ticket that lets this row in for a minute. */
export const VoiceTicket = z.strictObject({
    url: z.string().min(1),
    token: z.string().min(1),
});

export type VoiceTicket = z.infer<typeof VoiceTicket>;

/** What a ticket request came back with (`RoomVoiceTicketController`). */
export type VoiceTicketOutcome =
    | { kind: 'ticket'; ticket: VoiceTicket }
    /** 422: the account has not accepted the voice's sentence. */
    | { kind: 'agreement' }
    /** 401, 403, 404, 419: not admitted, not present, the voice off, the room closed, the session over. */
    | { kind: 'refused' }
    /** 503 `{status: "busy"}`: the instance has no room for another voice in use (ADR 031 decision 38). */
    | { kind: 'busy' }
    /** 503, a limit, a network failure: the voice server is not there now. */
    | { kind: 'unavailable' };

/** Why the browser did not give the microphone (`rooms.voice.errors.*`). */
export type MicError = 'denied' | 'no_mic';

/** Waits before each try for a new ticket when the voice room was closed under the page. */
export const REJOIN_MS = [0, 1000, 3000] as const;

/** The browser's own processing of the captured microphone (ADR 031 decision 31). */
export const AUDIO_CAPTURE = {
    echoCancellation: true,
    noiseSuppression: true,
    autoGainControl: true,
} as const;

/**
 * Audio as ADR 031 decision 31 sets it: Opus speech, DTX and RED, the
 * browser's own processing. A closed microphone is a disabled track the
 * page keeps (`stopMicTrackOnMute: false`), and a voice room closed under
 * the page leaves it to the page (`stopLocalTrackOnUnpublish: false`):
 * decision 35, as amended 2026-11-19 — C4 stopped it at every close.
 */
export function roomOptions(lib: VoiceLibrary): LiveKit.RoomOptions {
    return {
        adaptiveStream: false,
        dynacast: false,
        disconnectOnPageLeave: true,
        stopLocalTrackOnUnpublish: false,
        audioCaptureDefaults: { ...AUDIO_CAPTURE },
        publishDefaults: {
            audioPreset: lib.AudioPresets.speech,
            dtx: true,
            red: true,
            stopMicTrackOnMute: false,
        },
    };
}

/** Every connection: no ICE server at all, so the voice server's own list is never used (ADR 031 decision 2). */
export function connectOptions(): LiveKit.RoomConnectOptions {
    return { autoSubscribe: true, rtcConfig: { iceServers: [] } };
}

/** Where a heard voice's `<audio>` element goes: the page's body, hidden. */
export type AudioHost = {
    append: (element: HTMLMediaElement) => void;
};

export type VoiceOptions = {
    load?: () => Promise<VoiceLibrary>;
    /** Asks the app for a ticket; `agree` sends the account's agreement with it. */
    ticket: (agree: boolean) => Promise<VoiceTicketOutcome>;
    /** Told whenever what the page draws changes. */
    onChange: (connection: VoiceConnection) => void;
    /** A join refused for want of the agreement: the page shows the sentence again. */
    onAgreement?: () => void;
    /** The microphone could not be had (the bar's note). */
    onMicError?: (error: MicError) => void;
    /** The page was hidden while its microphone was open, and closed it (`rooms.voice.hidden_mic`). */
    onHiddenMic?: () => void;
    /** Whether the browser plays the voice yet; false until a tap unlocks it (`unlockAudio()`). */
    onAudio?: (playing: boolean) => void;
    page: ShownPage;
    audio: AudioHost;
    wait?: (ms: number) => Promise<void>;
};

type Room = InstanceType<VoiceLibrary['Room']>;
type MicTrack = LiveKit.LocalAudioTrack;

/** A capture the browser has not ended. */
function liveTrack(track: MicTrack): boolean {
    return track.mediaStreamTrack.readyState !== 'ended';
}

/**
 * One page's voice: `join()`, `setMic()`, `leave()`, `dispose()`. It holds
 * at most one connection, and reports every change through `onChange`.
 */
export class VoiceLink {
    private lib: VoiceLibrary | null = null;
    private room: Room | null = null;
    private connection: VoiceConnection = { kind: 'idle' };
    /** The owner's microphone as the owner left it: only a tap, the hidden page or the host's mute changes it. */
    private micOn = false;
    private opening = false;
    /** The page's one capture of the microphone, made at the owner's first open and kept, disabled while closed, until the voice is left. */
    private mic: MicTrack | null = null;
    /** The connection the capture is published in, if any. */
    private micRoom: Room | null = null;
    /** Whether the player is in the voice by their own choice: a leave or a refusal ends it. */
    private wanted = false;
    private rejoining = false;
    private leaving = false;
    private disposed = false;
    private readonly options: VoiceOptions;
    private readonly load: () => Promise<VoiceLibrary>;
    private readonly wait: (ms: number) => Promise<void>;
    private readonly stopWatching: () => void;

    constructor(options: VoiceOptions) {
        this.options = options;
        this.load = options.load ?? loadVoiceLibrary;
        this.wait =
            options.wait ??
            ((ms) =>
                new Promise((resolve) => globalThis.setTimeout(resolve, ms)));
        const onVisibility = () => this.visibilityChanged();
        this.options.page.addEventListener('visibilitychange', onVisibility);
        this.stopWatching = () =>
            this.options.page.removeEventListener(
                'visibilitychange',
                onVisibility,
            );
    }

    get current(): VoiceConnection {
        return this.connection;
    }

    /**
     * The page joining by itself (ADR 031 decision 40), or the player's tap
     * on «Səsə qoşul» or «Razıyam, qoşul» (`agree`), «Yenidən cəhd et»,
     * «Yenidən qoşul», «Burada qoşul». It listens and captures nothing.
     */
    async join(agree = false): Promise<void> {
        if (
            this.disposed ||
            this.room !== null ||
            this.connection.kind === 'joining'
        ) {
            return;
        }

        this.wanted = true;
        this.micOn = false;
        this.report({ kind: 'joining' });

        // The library and the ticket at once, not one after the other: on a
        // phone's network the ticket is a round trip and the library's first
        // download most of a second (ADR 031 decision 31, as C5 measured it).
        const [lib, outcome] = await Promise.all([
            this.lib !== null
                ? Promise.resolve(this.lib)
                : this.load().catch(() => null),
            this.askTicket(agree),
        ]);

        if (lib !== null) {
            this.lib = lib;
        }

        if (!this.wanted || this.disposed) {
            return;
        }

        if (lib === null) {
            this.fail('unreachable');

            return;
        }

        switch (outcome.kind) {
            case 'agreement':
                this.wanted = false;
                this.releaseMic();
                this.report({ kind: 'idle' });
                this.options.onAgreement?.();

                return;
            case 'refused':
                this.wanted = false;
                this.releaseMic();
                this.report({ kind: 'idle' });

                return;
            case 'busy':
                this.fail('busy');

                return;
            case 'unavailable':
                this.fail('unreachable');

                return;
            case 'ticket':
                if (
                    !(await this.connect(lib, outcome.ticket)) &&
                    this.wanted &&
                    !this.disposed
                ) {
                    this.fail('unreachable');
                }
        }
    }

    /**
     * The owner's tap on the microphone. The first `true` of the page
     * captures it — the browser's own question, never earlier — and every
     * later one enables the kept capture; `false` disables it.
     */
    async setMic(on: boolean): Promise<void> {
        const room = this.room;
        const lib = this.lib;

        if (room === null || lib === null || this.connection.kind !== 'in') {
            return;
        }

        if (!on) {
            this.micOn = false;
            this.opening = false;
            this.emit();
            await this.mic?.mute().catch(() => undefined);

            return;
        }

        if (this.micOn || this.opening) {
            return;
        }

        this.opening = true;
        this.emit();

        try {
            const track = await this.capture(lib);
            const still = () =>
                track !== null &&
                this.mic === track &&
                liveTrack(track) &&
                this.room === room &&
                this.opening;

            if (track !== null && still()) {
                await this.publishMic(room, track);
            }

            // Enabled only while it is still this owner's open capture: the browser may have ended it
            // (a call, the device gone) while it was published, and the library would capture an ended
            // track again by itself — a microphone nobody tapped.
            if (track !== null && still()) {
                await track.unmute();
                this.micOn = still();
            }

            if (track !== null && !this.micOn && this.mic === track) {
                // Closed, ended or the voice room changed while the browser asked: kept closed, or released.
                await track.mute().catch(() => undefined);
            }
        } catch (error) {
            this.micOn = false;
            this.options.onMicError?.(micError(error));
        } finally {
            this.opening = false;
        }

        // A hidden page closes what a late answer of the browser opened.
        if (this.micOn && this.options.page.visibilityState === 'hidden') {
            this.closeHiddenMic();
        }

        this.emit();
    }

    /** «Səsdən çıx», or the voice going off: one tap, and nothing of the game changes. */
    async leave(): Promise<void> {
        this.wanted = false;
        // Stopped, not unpublished: the voice room is left at once, and an unpublish would renegotiate a connection that is closing.
        this.releaseMic(false);
        const room = this.room;
        this.room = null;
        this.report({ kind: 'idle' });

        if (room !== null) {
            this.leaving = true;

            try {
                await room.disconnect();
            } catch {
                // Gone already.
            } finally {
                this.leaving = false;
            }
        }
    }

    /** A tap anywhere once the browser held the voice back (iOS Safari, ADR 031 "Who hears where"). */
    unlockAudio(): void {
        void this.room?.startAudio().catch(() => undefined);
    }

    dispose(): void {
        this.disposed = true;
        this.stopWatching();
        void this.leave();
    }

    /** The kept capture, or a new one — the browser's question, where it asks — when there is none or the browser ended it; null once the page left the voice meanwhile. */
    private async capture(lib: VoiceLibrary): Promise<MicTrack | null> {
        if (this.mic !== null && liveTrack(this.mic)) {
            return this.mic;
        }

        this.dropMic();
        const track = await lib.createLocalAudioTrack({ ...AUDIO_CAPTURE });

        if (!this.wanted || this.disposed || this.mic !== null) {
            track.stop();

            return this.mic;
        }

        this.mic = track;
        track.on(lib.TrackEvent.Ended, () => this.micEnded(track));

        return track;
    }

    /** Publishes the kept capture in this voice room, once. */
    private async publishMic(room: Room, track: MicTrack): Promise<void> {
        if (this.micRoom !== room) {
            await room.localParticipant.publishTrack(track);
            this.micRoom = room;
        }
    }

    /**
     * Stops the capture, so the device's indicator goes out: the page left
     * the voice, the host muted the player, or the browser ended it. The
     * next «Mikrofonu aç» captures again.
     */
    private releaseMic(unpublish = true): void {
        this.micOn = false;
        this.opening = false;
        this.dropMic(unpublish);
    }

    /** Stops the capture, if any, and unpublishes it from a voice room the page stays in. */
    private dropMic(unpublish = true): void {
        const track = this.mic;
        const room = this.micRoom;
        this.mic = null;
        this.micRoom = null;

        if (track === null) {
            return;
        }

        if (unpublish && room !== null) {
            void room.localParticipant
                .unpublishTrack(track, true)
                .catch(() => undefined);
        }

        track.stop();
    }

    /** The browser ended the capture (a call, the device gone): closed first, so nothing captures again without a tap, then released. */
    private micEnded(track: MicTrack): void {
        if (track !== this.mic) {
            return;
        }

        this.micOn = false;
        this.opening = false;
        void track
            .mute()
            .catch(() => undefined)
            .finally(() => {
                if (this.mic === track) {
                    this.releaseMic();
                    this.emit();
                }
            });
        this.emit();
    }

    private async askTicket(agree: boolean): Promise<VoiceTicketOutcome> {
        try {
            return await this.options.ticket(agree);
        } catch {
            return { kind: 'unavailable' };
        }
    }

    /** Connects with no ICE server; true once in. The microphone stays as `micOn` says (off at a join). */
    private async connect(
        lib: VoiceLibrary,
        ticket: VoiceTicket,
    ): Promise<boolean> {
        const room = new lib.Room(roomOptions(lib));
        this.watch(lib, room);
        this.room = room;

        try {
            await room.connect(ticket.url, ticket.token, connectOptions());
        } catch {
            if (this.room === room) {
                this.room = null;
            }

            room.removeAllListeners();

            return false;
        }

        if (this.room !== room || !this.wanted || this.disposed) {
            // Left while it connected: out again at once.
            room.removeAllListeners();
            void room.disconnect().catch(() => undefined);

            if (this.room === room) {
                this.room = null;
            }

            return false;
        }

        this.report(this.inState(room));
        this.options.onAudio?.(room.canPlaybackAudio);
        void room.startAudio().catch(() => undefined);

        return true;
    }

    private watch(lib: VoiceLibrary, room: Room): void {
        const E = lib.RoomEvent;
        const update = () => {
            if (this.room === room && this.connection.kind === 'in') {
                this.emit();
            }
        };

        for (const event of [
            E.ParticipantConnected,
            E.ParticipantDisconnected,
            E.TrackPublished,
            E.TrackUnpublished,
            E.TrackMuted,
            E.TrackUnmuted,
            E.LocalTrackPublished,
            E.LocalTrackUnpublished,
            E.ActiveSpeakersChanged,
        ] as const) {
            room.on(event, update);
        }

        room.on(E.TrackSubscribed, (track) => {
            if (track.kind === lib.Track.Kind.Audio) {
                const element = track.attach();
                element.hidden = true;
                this.options.audio.append(element);
            }
        });
        room.on(E.TrackUnsubscribed, (track) => {
            for (const element of track.detach()) {
                element.remove();
            }
        });
        // The host's mute takes the permission to publish: the microphone is released and stays closed (decisions 10, 35).
        room.on(E.ParticipantPermissionsChanged, (_before, participant) => {
            if (
                participant === room.localParticipant &&
                participant.permissions?.canPublish === false
            ) {
                this.releaseMic();
            }

            update();
        });
        room.on(E.Reconnecting, update);
        room.on(E.SignalReconnecting, update);
        room.on(E.Reconnected, update);
        room.on(E.AudioPlaybackStatusChanged, () => {
            this.options.onAudio?.(room.canPlaybackAudio);
        });
        room.on(E.MediaDevicesError, (error) => {
            this.options.onMicError?.(micError(error));
        });
        room.on(E.Disconnected, (reason) => {
            void this.disconnected(lib, room, reason);
        });
    }

    private async disconnected(
        lib: VoiceLibrary,
        room: Room,
        reason: LiveKit.DisconnectReason | undefined,
    ): Promise<void> {
        room.removeAllListeners();

        if (this.room !== room) {
            return;
        }

        this.room = null;
        // The capture outlives the voice room (`stopLocalTrackOnUnpublish: false`), published nowhere now.
        this.micRoom = null;
        const R = lib.DisconnectReason;

        if (this.leaving || !this.wanted || this.disposed) {
            return;
        }

        if (reason === R.DUPLICATE_IDENTITY) {
            this.fail('elsewhere');

            return;
        }

        if (
            reason === R.ROOM_DELETED ||
            reason === R.ROOM_CLOSED ||
            reason === R.PARTICIPANT_REMOVED
        ) {
            await this.rejoin(lib, this.micOn);

            return;
        }

        if (reason === R.CLIENT_INITIATED) {
            // The library left with the page (`pagehide`, a frozen tab), and stopped the capture with it: back in, listening, when the page is shown.
            this.releaseMic();
            this.report({
                kind: 'in',
                live: emptyLive(),
                mic: 'off',
                captured: false,
                reconnecting: true,
            });

            if (this.options.page.visibilityState === 'visible') {
                await this.rejoin(lib, false);
            }

            return;
        }

        // The library's own tries ran out (about 45 s).
        this.fail('dropped');
    }

    /** A new ticket for the voice room as it is now, the kept microphone published again as it was a moment before. */
    private async rejoin(lib: VoiceLibrary, mic: boolean): Promise<void> {
        if (this.rejoining) {
            return;
        }

        this.rejoining = true;
        this.micOn = false;
        this.report({
            kind: 'in',
            live: emptyLive(),
            mic: 'off',
            captured: this.mic !== null && liveTrack(this.mic),
            reconnecting: true,
        });

        try {
            for (const delay of REJOIN_MS) {
                await this.wait(delay);

                if (!this.wanted || this.disposed || this.room !== null) {
                    return;
                }

                const outcome = await this.askTicket(false);

                if (!this.wanted || this.disposed) {
                    return;
                }

                if (
                    outcome.kind === 'refused' ||
                    outcome.kind === 'agreement'
                ) {
                    // Turned off, removed, gone: the state says which.
                    this.wanted = false;
                    this.releaseMic();
                    this.report({ kind: 'idle' });

                    return;
                }

                if (outcome.kind === 'busy') {
                    // Full: a tap tries again, never the page.
                    this.fail('busy');

                    return;
                }

                if (
                    outcome.kind === 'ticket' &&
                    (await this.connect(lib, outcome.ticket))
                ) {
                    await this.restoreMic(mic);

                    return;
                }
            }

            this.fail('unreachable');
        } finally {
            this.rejoining = false;
        }
    }

    /** The kept capture into the new voice room: open only if it was open a moment before, else published closed. */
    private async restoreMic(open: boolean): Promise<void> {
        const room = this.room;
        const track = this.mic;

        if (room === null || track === null) {
            return;
        }

        if (!liveTrack(track)) {
            this.releaseMic();
            this.emit();

            return;
        }

        if (open) {
            await this.setMic(true);

            return;
        }

        try {
            await track.mute();
            await this.publishMic(room, track);
        } catch {
            // A voice room that will not take it: the next «Mikrofonu aç» publishes it.
        }

        this.emit();
    }

    private visibilityChanged(): void {
        if (this.options.page.visibilityState === 'hidden') {
            if (this.micOn || this.opening) {
                this.closeHiddenMic();
                this.emit();
            }

            return;
        }

        // Shown again: back into a voice the page left while hidden.
        if (
            this.wanted &&
            this.room === null &&
            !this.rejoining &&
            this.connection.kind === 'in' &&
            this.lib !== null
        ) {
            void this.rejoin(this.lib, false);
        }
    }

    /** No open microphone on a page nobody sees: closed at once — disabled, the capture kept — and said when the page is shown. */
    private closeHiddenMic(): void {
        this.micOn = false;
        this.opening = false;
        void this.mic?.mute().catch(() => undefined);
        this.options.onHiddenMic?.();
    }

    private fail(trouble: VoiceTrouble): void {
        this.wanted = false;
        this.releaseMic();
        this.report({ kind: 'failed', trouble });
    }

    private emit(): void {
        if (this.room !== null && this.connection.kind === 'in') {
            this.report(this.inState(this.room));
        }
    }

    private inState(room: Room): VoiceConnection {
        const me = room.localParticipant.identity;
        const remote = [...room.remoteParticipants.values()];
        const live: VoiceLive = {
            here: [me, ...remote.map((participant) => participant.identity)],
            open: [
                ...(this.micOn ? [me] : []),
                ...remote
                    .filter((participant) => participant.isMicrophoneEnabled)
                    .map((participant) => participant.identity),
            ],
            speaking: room.activeSpeakers.map(
                (participant) => participant.identity,
            ),
        };

        return {
            kind: 'in',
            live,
            mic: this.opening ? 'opening' : this.micOn ? 'on' : 'off',
            captured: this.mic !== null && liveTrack(this.mic),
            reconnecting:
                this.lib !== null &&
                room.state !== this.lib.ConnectionState.Connected,
        };
    }

    private report(connection: VoiceConnection): void {
        this.connection = connection;

        if (!this.disposed) {
            this.options.onChange(connection);
        }
    }
}

function emptyLive(): VoiceLive {
    return { here: [], open: [], speaking: [] };
}

/** The browser's refusal said in words: no permission, or no microphone. */
export function micError(error: unknown): MicError {
    const name =
        typeof error === 'object' && error !== null && 'name' in error
            ? String(error.name)
            : '';

    return [
        'NotFoundError',
        'DevicesNotFoundError',
        'OverconstrainedError',
        'NotReadableError',
    ].includes(name)
        ? 'no_mic'
        : 'denied';
}
