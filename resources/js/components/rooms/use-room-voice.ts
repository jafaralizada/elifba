import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { update as setStandingRoute } from '@/routes/rooms/members/voice';
import { update as setVoiceRoute } from '@/routes/rooms/voice';
import { store as ticketRoute } from '@/routes/rooms/voice/tickets';
import type { RoomState } from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';
import { VoiceLink, VoiceTicket } from '@/rooms/voice';
import type { MicError, VoiceTicketOutcome } from '@/rooms/voice';
import { voiceView } from '@/rooms/voice-view';
import type {
    VoiceConnection,
    VoiceRowAction,
    VoiceView,
} from '@/rooms/voice-view';

/** A note over the page, said once and gone after `NOTE_MS` or the next tap of the microphone. */
export type RoomVoiceNote =
    | 'hidden_mic'
    | 'muted_you'
    | 'turned_off'
    | `errors.${MicError}`;

export const NOTE_MS = 6000;

/** The page's live voice as `VoiceButton` and its panel draw it, and the taps they offer. */
export type RoomVoice = {
    /** What is drawn (`voiceView()`); null: nothing of voice. */
    view: VoiceView | null;
    note: RoomVoiceNote | null;
    /** The sheet with the sentence, before an account's first join. */
    consent: boolean;
    setConsent: (open: boolean) => void;
    /** The panel «Canlı səs» the head's button opens, in every standing. */
    sheet: boolean;
    setSheet: (open: boolean) => void;
    /** «Səsə qoşul»: the sentence first while the account has not accepted it. */
    join: () => void;
    /** «Razıyam, qoşul». */
    agree: () => void;
    /** «Yenidən cəhd et», «Yenidən qoşul», «Burada qoşul». */
    retry: () => void;
    /** The panel's microphone: the owner's tap, or why it is muted. */
    tapMic: () => void;
    /** «Səsdən çıx». */
    leave: () => void;
    /** The host's write in flight: a row's id, or `off`. */
    pending: string | null;
    /** The host's write that failed: the panel says so. */
    failed: boolean;
    /** «Susdur», «Danışmağa icazə ver», «Səsə burax». */
    act: (member: string, action: VoiceRowAction) => void;
    /** «Canlı səsi söndür». */
    turnOff: () => void;
};

function secureNow(): boolean {
    return (
        window.isSecureContext &&
        typeof navigator.mediaDevices?.getUserMedia === 'function'
    );
}

const never = () => () => {};

/** 200 with a ticket, 422 the agreement, a refusal, or anything else: the voice server is not there now. */
function ticketOutcome(status: number, body: unknown): VoiceTicketOutcome {
    if (status === 200) {
        const ticket = VoiceTicket.safeParse(body);

        return ticket.success
            ? { kind: 'ticket', ticket: ticket.data }
            : { kind: 'unavailable' };
    }

    if (status === 422) {
        return { kind: 'agreement' };
    }

    return [401, 403, 404, 419].includes(status)
        ? { kind: 'refused' }
        : { kind: 'unavailable' };
}

/**
 * A room page's live voice (docs/frontend.md rule 15 "Live voice"; ADR 031
 * decisions 11, 14, 26): what `voiceView()` draws from the state and this
 * page's own connection, and the taps. The connection (`VoiceLink`) is made
 * at the first tap of «Səsə qoşul», so a page that never joins never loads
 * the library and asks for no ticket. The voice going off, the viewer's
 * standing gone or the page leaving close it; a phone's game sounds are
 * another thing and are untouched by any of it (ADR 031 decision 25).
 */
export function useRoomVoice({
    roomId,
    state,
    onChanged,
}: {
    roomId: string;
    state: RoomState | null;
    /** After a host's write: the page asks for the state now rather than wait for the signal. */
    onChanged: () => void;
}): RoomVoice {
    const link = useRef<VoiceLink | null>(null);
    const [connection, setConnection] = useState<VoiceConnection>({
        kind: 'idle',
    });
    // A new object each time, so a note said again starts its time again.
    const [note, setNote] = useState<{ note: RoomVoiceNote } | null>(null);
    // The microphone closed by a hidden page: said when the page is shown again.
    const hiddenMic = useRef(false);
    const [consent, setConsent] = useState(false);
    const [sheet, setSheet] = useState(false);
    const [pending, setPending] = useState<string | null>(null);
    const [failed, setFailed] = useState(false);
    const [audioHeld, setAudioHeld] = useState(false);
    const secure = useSyncExternalStore(never, secureNow, () => true);
    const view = state === null ? null : voiceView(state, connection, secure);
    const on = state?.voice?.on === true;
    const host = state?.me.host === true;
    const [shownOn, setShownOn] = useState(on);

    const say = (next: RoomVoiceNote) => setNote({ note: next });

    // The voice went off while this page showed it: it says so for a moment (not to the host, who did it).
    if (shownOn !== on) {
        setShownOn(on);

        if (!on && !host && state !== null && state.voice !== null) {
            setNote({ note: 'turned_off' });
        }
    }

    const linkFor = (): VoiceLink => {
        link.current ??= new VoiceLink({
            ticket: async (agree) => {
                const reply = await roomRequest(
                    'POST',
                    ticketRoute.url(roomId),
                    agree ? { agree: true } : {},
                );

                return ticketOutcome(reply.status, reply.body);
            },
            onChange: setConnection,
            onAgreement: () => setConsent(true),
            onMicError: (error) => say(`errors.${error}`),
            onHiddenMic: () => {
                hiddenMic.current = true;
            },
            onAudio: (playing) => setAudioHeld(!playing),
            page: document,
            audio: { append: (element) => document.body.append(element) },
        });

        return link.current;
    };

    // Nothing of voice to draw any more — the voice off, the viewer removed or gone: out of the voice.
    const out = view === null;

    useEffect(() => {
        if (
            out &&
            link.current !== null &&
            link.current.current.kind !== 'idle'
        ) {
            void link.current.leave();
        }
    }, [out]);

    useEffect(() => () => link.current?.dispose(), []);

    useEffect(() => {
        if (out) {
            return;
        }

        const shown = () => {
            if (document.visibilityState === 'visible' && hiddenMic.current) {
                hiddenMic.current = false;
                setNote({ note: 'hidden_mic' });
            }
        };
        document.addEventListener('visibilitychange', shown);

        return () => document.removeEventListener('visibilitychange', shown);
    }, [out]);

    // A note goes after a while.
    useEffect(() => {
        if (note === null) {
            return;
        }

        const timer = window.setTimeout(() => setNote(null), NOTE_MS);

        return () => window.clearTimeout(timer);
    }, [note]);

    // A browser that held the voice back (iOS Safari) plays it from the next tap anywhere.
    useEffect(() => {
        if (!audioHeld) {
            return;
        }

        const unlock = () => link.current?.unlockAudio();
        document.addEventListener('pointerdown', unlock, { capture: true });
        document.addEventListener('keydown', unlock, { capture: true });

        return () => {
            document.removeEventListener('pointerdown', unlock, {
                capture: true,
            });
            document.removeEventListener('keydown', unlock, { capture: true });
        };
    }, [audioHeld]);

    const hostWrite = (key: string, request: Promise<{ status: number }>) => {
        setPending(key);
        setFailed(false);
        void request
            .then((reply) => setFailed(reply.status !== 204))
            .catch(() => setFailed(true))
            .finally(() => {
                setPending(null);
                onChanged();
            });
    };

    return {
        view,
        note: note?.note ?? null,
        consent,
        setConsent,
        sheet: sheet && view !== null,
        setSheet,
        join: () => {
            if (state?.voice?.agreed === true) {
                void linkFor().join(false);
            } else {
                // One dialog at a time: the sentence in place of the panel, which comes back after «Razıyam, qoşul».
                setSheet(false);
                setConsent(true);
            }
        },
        agree: () => {
            setConsent(false);
            setSheet(true);
            void linkFor().join(true);
        },
        retry: () => void linkFor().join(false),
        tapMic: () => {
            if (view?.bar.form !== 'in') {
                return;
            }

            if (view.bar.mic === 'muted') {
                say('muted_you');

                return;
            }

            setNote(null);
            void linkFor().setMic(view.bar.mic === 'off');
        },
        leave: () => {
            setSheet(false);
            void link.current?.leave();
        },
        pending,
        failed,
        act: (member, action) =>
            hostWrite(
                member,
                roomRequest(
                    'PUT',
                    setStandingRoute.url({ room: roomId, member }),
                    { standing: action === 'mute' ? 'muted' : 'open' },
                ),
            ),
        turnOff: () => {
            setSheet(false);
            hostWrite(
                'off',
                roomRequest('PUT', setVoiceRoute.url(roomId), { on: false }),
            );
        },
    };
}
