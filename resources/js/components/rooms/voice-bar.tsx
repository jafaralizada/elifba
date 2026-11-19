import { Link } from '@inertiajs/react';
import {
    Ban,
    Check,
    ChevronUp,
    HeadphoneOff,
    Headphones,
    Hourglass,
    LogOut,
    Mic,
    MicOff,
    Shield,
    Smartphone,
    X,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { SHEET, SwitchRow } from '@/components/rooms/room-tools';
import type {
    RoomVoice,
    RoomVoiceNote,
} from '@/components/rooms/use-room-voice';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { privacy } from '@/routes';
import { update as setVoiceRoute } from '@/routes/rooms/voice';
import type { RoomState } from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';
import type {
    VoiceBarForm,
    VoiceRow,
    VoiceRowState,
    VoiceSpeaker,
} from '@/rooms/voice-view';

/**
 * The voice bar (docs/frontend.md rule 15 "Live voice", ADR 031 decision 34,
 * variant A «Zolaq»): how a player meets the live voice, on every moment of
 * the room page while the host has it on — a 56px bar over the tab bar,
 * with a line above it for a note or, on the host's phone, for a newcomer
 * who waits. Its forms: invited («Səsə qoşul»), waiting, in the voice (who
 * speaks, as one button that opens the sheet «Canlı səs», and the
 * microphone as a worded pill), and trouble. It draws `voice.view` and
 * nothing it worked out itself, and nothing of it is there while the voice
 * is off. The sheets it opens are here too: the sentence before a first
 * join (`VoiceConsent`) and «Canlı səs» (`VoiceSheet`).
 */
export function VoiceBar({ voice }: { voice: RoomVoice }) {
    const { view, note } = voice;

    if (view === null && note === null) {
        return null;
    }

    return (
        <div data-voice-bar="">
            {note !== null && <NoteRow note={note} />}
            {view !== null && view.waiting !== null && (
                <WaitingRow
                    name={view.waiting.name}
                    pending={voice.pending === view.waiting.id}
                    onAdmit={() =>
                        view.waiting !== null &&
                        voice.act(view.waiting.id, 'admit')
                    }
                />
            )}
            {view !== null && <Bar bar={view.bar} voice={voice} />}
            {view !== null && (
                <>
                    <VoiceConsent voice={voice} />
                    <VoiceSheet voice={voice} />
                </>
            )}
        </div>
    );
}

/**
 * The bar in a round, where no action bar stands: stuck above the tab bar
 * as the action bar is, at the page's end (`mt-auto`).
 */
export function VoiceDock({ voice }: { voice: RoomVoice }) {
    if (voice.view === null && voice.note === null) {
        return null;
    }

    return (
        <div className="sticky bottom-[calc(4rem+env(safe-area-inset-bottom))] z-20 mt-auto lg:bottom-0">
            <VoiceBar voice={voice} />
        </div>
    );
}

/** One row of the bar's width: the page's column inside a full-width band. */
function Band({
    children,
    className,
    inner,
}: {
    children: ReactNode;
    className?: string;
    inner?: string;
}) {
    return (
        <div className={cn('border-t bg-background', className)}>
            <div
                className={cn(
                    'mx-auto flex w-full max-w-xl items-center gap-2 px-4 md:px-6',
                    inner,
                )}
            >
                {children}
            </div>
        </div>
    );
}

const NOTE_ICONS: Record<RoomVoiceNote, typeof Mic> = {
    hidden_mic: MicOff,
    muted_you: MicOff,
    turned_off: HeadphoneOff,
    'errors.denied': MicOff,
    'errors.no_mic': MicOff,
};

function NoteRow({ note }: { note: RoomVoiceNote }) {
    const { t } = useTranslation();
    const Icon = NOTE_ICONS[note];

    return (
        <Band className="bg-muted" inner="min-h-11 py-2">
            <Icon
                aria-hidden
                className="size-4 shrink-0 text-muted-foreground"
            />
            <p
                role="status"
                className="min-w-0 flex-1 text-[0.8125rem] leading-snug"
            >
                {t(`rooms.voice.${note}`)}
            </p>
        </Band>
    );
}

function WaitingRow({
    name,
    pending,
    onAdmit,
}: {
    name: string | null;
    pending: boolean;
    onAdmit: () => void;
}) {
    const { t } = useTranslation();

    return (
        <Band className="bg-muted" inner="min-h-12 py-1.5">
            <Hourglass
                aria-hidden
                className="size-4 shrink-0 text-muted-foreground"
            />
            <span className="min-w-0 flex-1 text-[0.8125rem] leading-snug">
                {t('rooms.voice.waiting_host', {
                    name: name ?? t('rooms.show.nameless'),
                })}
            </span>
            <Button
                type="button"
                variant="outline"
                className="h-10 shrink-0"
                disabled={pending}
                onClick={onAdmit}
            >
                {pending ? <Spinner /> : <Check />}
                {t('rooms.voice.admit')}
            </Button>
        </Band>
    );
}

/** The voice's filled button and pill: the fill in the voice's colour, its text readable on it. */
const FILLED =
    'border-(--voice) bg-(--voice) text-(--voice-on) hover:bg-(--voice)/90 hover:text-(--voice-on)';

function Bar({ bar, voice }: { bar: VoiceBarForm; voice: RoomVoice }) {
    const { t } = useTranslation();

    switch (bar.form) {
        case 'insecure':
            return (
                <Band inner="min-h-14 py-1.5">
                    <HeadphoneOff
                        aria-hidden
                        className="size-5 shrink-0 text-muted-foreground"
                    />
                    <p className="min-w-0 flex-1 text-sm text-muted-foreground">
                        {t('rooms.voice.errors.insecure')}
                    </p>
                </Band>
            );
        case 'waiting':
            return (
                <Band inner="min-h-14 py-1.5">
                    <Hourglass
                        aria-hidden
                        className="size-5 shrink-0 text-(--voice-text)"
                    />
                    <p className="min-w-0 flex-1 text-[0.8125rem] leading-snug">
                        {t('rooms.voice.waiting_you')}
                    </p>
                </Band>
            );
        case 'invited':
            return (
                <Band className="bg-(--voice-tint)" inner="min-h-14 py-1.5">
                    <Headphones
                        aria-hidden
                        className="size-5 shrink-0 text-(--voice-text)"
                    />
                    <span className="flex min-w-0 flex-1 flex-col">
                        <span className="truncate text-[0.90625rem] font-semibold">
                            {t('rooms.voice.invite')}
                        </span>
                        <span className="truncate text-xs text-muted-foreground">
                            {t('rooms.voice.invite_line')}
                        </span>
                    </span>
                    <Button
                        type="button"
                        className={cn(
                            'h-11 shrink-0 rounded-full px-4',
                            FILLED,
                        )}
                        disabled={bar.joining}
                        onClick={voice.join}
                    >
                        {bar.joining ? <Spinner /> : <Headphones />}
                        {t('rooms.voice.join')}
                    </Button>
                </Band>
            );
        case 'trouble':
            return <Trouble trouble={bar.trouble} voice={voice} />;
        case 'in':
            return (
                <Band
                    className={cn(bar.mic === 'on' && 'bg-(--voice-tint)')}
                    inner="min-h-14 py-1 pr-3 md:pr-5"
                >
                    <button
                        type="button"
                        aria-haspopup="dialog"
                        onClick={() => voice.setSheet(true)}
                        className="-ml-1 flex min-h-11 min-w-0 flex-1 items-center gap-2.5 rounded-lg pl-1 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {bar.speaker.kind === 'none' ? (
                            <Headphones
                                aria-hidden
                                className="size-5 shrink-0 text-(--voice-text)"
                            />
                        ) : (
                            <SpeakingBars className="text-[1.25rem] text-(--voice-text)" />
                        )}
                        <span className="flex min-w-0 flex-col">
                            <span className="truncate text-[0.90625rem] font-semibold">
                                <SpeakerLine speaker={bar.speaker} />
                            </span>
                            <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                {t('rooms.voice.count', { count: bar.count })}
                                <ChevronUp aria-hidden className="size-3.5" />
                            </span>
                        </span>
                    </button>
                    <MicPill mic={bar.mic} onTap={voice.tapMic} />
                </Band>
            );
    }
}

function Trouble({
    trouble,
    voice,
}: {
    trouble: Extract<VoiceBarForm, { form: 'trouble' }>['trouble'];
    voice: RoomVoice;
}) {
    const { t } = useTranslation();

    if (trouble === 'reconnecting') {
        return (
            <Band inner="min-h-14 py-1.5">
                <Spinner className="size-5 shrink-0 text-(--voice-text)" />
                <p role="status" className="min-w-0 flex-1 text-sm">
                    {t('rooms.voice.reconnecting')}
                </p>
            </Band>
        );
    }

    const Icon = trouble === 'elsewhere' ? Smartphone : HeadphoneOff;
    const line =
        trouble === 'elsewhere'
            ? t('rooms.voice.elsewhere')
            : t('rooms.voice.errors.unreachable');
    const action =
        trouble === 'elsewhere'
            ? t('rooms.voice.here')
            : trouble === 'dropped'
              ? t('rooms.voice.rejoin')
              : t('rooms.voice.retry');

    return (
        <Band inner="min-h-14 py-1.5">
            <Icon
                aria-hidden
                className="size-5 shrink-0 text-muted-foreground"
            />
            <p
                role="status"
                className="min-w-0 flex-1 text-[0.8125rem] leading-snug"
            >
                {line}
            </p>
            <Button
                type="button"
                variant="outline"
                className="h-11 shrink-0 rounded-full"
                onClick={voice.retry}
            >
                {action}
            </Button>
        </Band>
    );
}

/** «:name danışır», «:name və :other danışır», «:count nəfər danışır», «Siz danışırsınız», «Heç kim danışmır». */
function SpeakerLine({ speaker }: { speaker: VoiceSpeaker }) {
    const { t } = useTranslation();
    const named = (name: string | null) => name ?? t('rooms.show.nameless');

    switch (speaker.kind) {
        case 'none':
            return t('rooms.voice.silent');
        case 'you':
            return t('rooms.voice.you_speak');
        case 'one':
            return t('rooms.voice.speaks', { name: named(speaker.name) });
        case 'two':
            return t('rooms.voice.speak_two', {
                name: named(speaker.name),
                other: named(speaker.other),
            });
        case 'many':
            return t('rooms.voice.speak_many', { count: speaker.count });
    }
}

/** Three bars that move while someone speaks, and stand still under `prefers-reduced-motion`. */
export function SpeakingBars({ className }: { className?: string }) {
    return (
        <span
            aria-hidden
            className={cn(
                'inline-flex h-[1em] w-[1em] shrink-0 items-center justify-center gap-[0.12em]',
                className,
            )}
        >
            {['h-[40%]', 'h-[90%]', 'h-[60%]'].map((height, index) => (
                <i
                    key={height}
                    className={cn(
                        'w-[0.13em] rounded-full bg-current motion-safe:animate-room-voice-bar',
                        height,
                    )}
                    style={{ animationDelay: `${index * 150}ms` }}
                />
            ))}
        </span>
    );
}

/**
 * The microphone, the bar's strongest control (docs/frontend.md rule 15
 * "Live voice"): a 48px button that says what a tap does — «Mikrofonu aç»,
 * filled in the voice's colour; «Mikrofonu söndür» with a pulsing dot while
 * open, outlined on the bar the open microphone tints; «Susdurulub», dashed.
 */
function MicPill({
    mic,
    onTap,
}: {
    mic: 'off' | 'opening' | 'on' | 'muted';
    onTap: () => void;
}) {
    const { t } = useTranslation();
    const open = mic === 'on';

    return (
        <button
            type="button"
            data-voice-mic={mic}
            title={mic === 'muted' ? t('rooms.voice.muted_you') : undefined}
            disabled={mic === 'opening'}
            onClick={onTap}
            className={cn(
                'inline-flex h-12 shrink-0 items-center gap-2 rounded-full border-[1.5px] px-4 text-[0.9375rem] font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait',
                open
                    ? 'border-(--voice) bg-background text-(--voice-text)'
                    : mic === 'muted'
                      ? 'border-dashed bg-card text-muted-foreground'
                      : cn(FILLED, 'shadow-sm'),
            )}
        >
            {mic === 'opening' ? (
                <Spinner className="size-5" />
            ) : open ? (
                <span aria-hidden className="relative flex size-2.5">
                    <span className="absolute inline-flex size-full rounded-full bg-(--voice) opacity-60 motion-safe:animate-ping" />
                    <span className="relative inline-flex size-2.5 rounded-full bg-(--voice)" />
                </span>
            ) : mic === 'muted' ? (
                <MicOff aria-hidden className="size-5" />
            ) : (
                <Mic aria-hidden className="size-5" />
            )}
            {open
                ? t('rooms.voice.mic.close')
                : mic === 'muted'
                  ? t('rooms.voice.mic.muted')
                  : t('rooms.voice.mic.open')}
        </button>
    );
}

/**
 * The sentence before an account's first join (ADR 031 decision 14): «Canlı
 * səs», `rooms.voice.agreement` in a tinted box, the three notes, «Razıyam,
 * qoşul» and «İndi yox», and the way to `/privacy`'s part «Canlı səs». A
 * sheet below `sm`, a dialog from there. Later joins are one tap.
 */
function VoiceConsent({ voice }: { voice: RoomVoice }) {
    const { t } = useTranslation();
    const notes = [
        ['mic', MicOff],
        ['host', Shield],
        ['near', Headphones],
    ] as const;

    return (
        <Dialog open={voice.consent} onOpenChange={voice.setConsent}>
            <DialogContent showCloseButton={false} className={SHEET}>
                <div className="flex flex-col gap-4 px-4 pt-5 pb-[max(env(safe-area-inset-bottom),1rem)]">
                    <DialogTitle className="flex items-center gap-2 text-lg">
                        <Headphones
                            aria-hidden
                            className="size-5 text-(--voice-text)"
                        />
                        {t('rooms.voice.title')}
                    </DialogTitle>
                    <DialogDescription className="rounded-xl border bg-(--voice-tint) p-3 text-[0.9375rem] leading-snug font-semibold text-foreground">
                        {t('rooms.voice.agreement')}
                    </DialogDescription>
                    <ul className="flex flex-col gap-2 text-sm leading-snug">
                        {notes.map(([key, Icon]) => (
                            <li key={key} className="flex items-start gap-2.5">
                                <Icon
                                    aria-hidden
                                    className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                                />
                                {t(`rooms.voice.note.${key}`)}
                            </li>
                        ))}
                    </ul>
                    <div className="flex flex-col gap-2">
                        <Button
                            type="button"
                            className={cn('h-13 text-base', FILLED)}
                            onClick={voice.agree}
                        >
                            <Headphones />
                            {t('rooms.voice.agree')}
                        </Button>
                        <DialogClose asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                className="h-11"
                            >
                                {t('rooms.voice.not_now')}
                            </Button>
                        </DialogClose>
                    </div>
                    <Link
                        href={`${privacy().url}#live-voice`}
                        className="self-center text-sm text-muted-foreground underline underline-offset-4"
                    >
                        {t('nav.privacy')}
                    </Link>
                </div>
            </DialogContent>
        </Dialog>
    );
}

const ROW_ICONS: Record<Exclude<VoiceRowState, 'speaking'>, typeof Mic> = {
    mic: Mic,
    listening: Headphones,
    away: HeadphoneOff,
    muted: MicOff,
    waiting: Hourglass,
};

/**
 * «Canlı səs» (rule 15 "The sheet «Canlı səs»"): «Səsdə: :count nəfər», a
 * 52px row for the host and each present player — the state's icon on a
 * disc, the name with «siz» or «aparıcı», the state in words — those who
 * speak first; «Səsdən çıx»; and for the host one 40px button on each
 * other row and «Canlı səsi söndür» at the end. No confirmation: each is
 * undone by its opposite.
 */
function VoiceSheet({ voice }: { voice: RoomVoice }) {
    const { t } = useTranslation();
    const view = voice.view;

    if (view === null || view.bar.form !== 'in') {
        return null;
    }

    return (
        <Dialog open={voice.sheet} onOpenChange={voice.setSheet}>
            <DialogContent
                showCloseButton={false}
                aria-describedby={undefined}
                className={SHEET}
            >
                <div className="flex items-center gap-3 border-b px-4 py-2">
                    <span className="flex min-w-0 flex-1 flex-col">
                        <DialogTitle className="flex items-center gap-2 text-lg">
                            <Headphones
                                aria-hidden
                                className="size-5 text-(--voice-text)"
                            />
                            {t('rooms.voice.title')}
                        </DialogTitle>
                        <span className="text-[0.8125rem] text-muted-foreground">
                            {t('rooms.voice.count', { count: view.bar.count })}
                        </span>
                    </span>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            className="size-11 shrink-0 rounded-full"
                            aria-label={t('common.close')}
                        >
                            <X className="size-5" />
                        </Button>
                    </DialogClose>
                </div>
                <div className="flex max-h-[60dvh] flex-col overflow-y-auto px-4">
                    <h3 className="sr-only">{t('rooms.voice.people')}</h3>
                    <ul className="flex flex-col divide-y">
                        {view.rows.map((row) => (
                            <PersonRow
                                key={row.id}
                                row={row}
                                pending={voice.pending === row.id}
                                onAct={(action) => voice.act(row.id, action)}
                            />
                        ))}
                    </ul>
                </div>
                <div className="flex flex-col gap-2 border-t px-4 pt-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                    {voice.failed && (
                        <InputError message={t('rooms.setup.change_failed')} />
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        className="h-11"
                        onClick={voice.leave}
                    >
                        <LogOut />
                        {t('rooms.voice.leave')}
                    </Button>
                    {view.host && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-11 text-red-600 hover:text-red-600 dark:text-red-400 dark:hover:text-red-400"
                            disabled={voice.pending === 'off'}
                            onClick={voice.turnOff}
                        >
                            {voice.pending === 'off' ? <Spinner /> : <Ban />}
                            {t('rooms.voice.turn_off')}
                        </Button>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

const ACTIONS = {
    mute: ['rooms.voice.mute', MicOff],
    unmute: ['rooms.voice.unmute', Mic],
    admit: ['rooms.voice.admit', Check],
} as const;

function PersonRow({
    row,
    pending,
    onAct,
}: {
    row: VoiceRow;
    pending: boolean;
    onAct: (action: NonNullable<VoiceRow['action']>) => void;
}) {
    const { t } = useTranslation();
    const speaking = row.state === 'speaking';
    const Icon = row.state === 'speaking' ? null : ROW_ICONS[row.state];
    const action = row.action === null ? null : ACTIONS[row.action];
    const ActionIcon = action?.[1] ?? Check;

    return (
        <li className="flex min-h-13 items-center gap-3 py-1.5">
            <span
                className={cn(
                    'flex size-8 shrink-0 items-center justify-center rounded-full',
                    speaking
                        ? 'bg-(--voice) text-(--voice-on)'
                        : 'bg-muted text-muted-foreground',
                )}
            >
                {Icon === null ? (
                    <SpeakingBars className="text-base" />
                ) : (
                    <Icon aria-hidden className="size-4" />
                )}
            </span>
            <span className="flex min-w-0 flex-1 flex-col">
                <span className="flex min-w-0 items-baseline gap-1.5">
                    <span className="truncate font-medium">
                        {row.name ?? t('rooms.show.nameless')}
                    </span>
                    {(row.mine || row.host) && (
                        <span className="shrink-0 text-xs text-muted-foreground">
                            {row.mine
                                ? t('rooms.show.you')
                                : t('rooms.show.host')}
                        </span>
                    )}
                </span>
                <span
                    className={cn(
                        'text-[0.8125rem]',
                        speaking
                            ? 'font-semibold text-(--voice-text)'
                            : 'text-muted-foreground',
                    )}
                >
                    {t(`rooms.voice.state.${row.state}`)}
                </span>
            </span>
            {row.action !== null && action !== null && (
                <Button
                    type="button"
                    variant="outline"
                    className="h-10 shrink-0 px-3 text-[0.8125rem]"
                    disabled={pending}
                    onClick={() => row.action !== null && onAct(row.action)}
                >
                    {pending ? <Spinner /> : <ActionIcon aria-hidden />}
                    {t(action[0])}
                </Button>
            )}
        </li>
    );
}

/** The host's switch as the tools sheet shows and changes it: a tap sends `{on}` and shows it until the state moves. */
export function useVoiceSwitch({
    roomId,
    on,
    onChanged,
}: {
    roomId: string;
    on: boolean;
    onChanged: () => void;
}) {
    const { t } = useTranslation();
    const [tap, setTap] = useState<{ on: boolean; over: boolean } | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const shown = tap !== null && tap.over === on ? tap.on : on;

    const toggle = () => {
        if (busy) {
            return;
        }

        setTap({ on: !shown, over: on });
        setBusy(true);
        setError(null);
        void roomRequest('PUT', setVoiceRoute.url(roomId), { on: !shown })
            .then((reply) => {
                if (reply.status === 204) {
                    return;
                }

                const body = reply.body as {
                    errors?: Record<string, string[]>;
                } | null;
                setTap(null);
                setError(
                    reply.status === 422
                        ? (Object.values(body?.errors ?? {})[0]?.[0] ??
                              t('rooms.voice.errors.busy'))
                        : t('rooms.setup.change_failed'),
                );
            })
            .catch(() => {
                setTap(null);
                setError(t('rooms.setup.change_failed'));
            })
            .finally(() => {
                setBusy(false);
                onChanged();
            });
    };

    return { on: shown, busy, error, toggle };
}

/**
 * «Canlı səs», the first row of «Otağın idarəsi» while the instance's voice
 * is on (rule 15 "The host turns it on"): `Headphones`, the switch and its
 * line — what turning it on means, or that turning it off stops it for
 * everyone at once — and «Hazırda canlı səs üçün yer yoxdur» when the
 * instance has no room for another voice.
 */
export function VoiceSwitchRow({
    roomId,
    state,
    onChanged,
    disabled = false,
}: {
    roomId: string;
    state: RoomState;
    onChanged: () => void;
    disabled?: boolean;
}) {
    const { t } = useTranslation();
    const voiceSwitch = useVoiceSwitch({
        roomId,
        on: state.voice?.on === true,
        onChanged,
    });

    return (
        <div className="flex flex-col">
            <SwitchRow
                icon={
                    <Headphones
                        aria-hidden
                        className="size-5 shrink-0 text-(--voice-text)"
                    />
                }
                label={t('rooms.voice.title')}
                line={
                    voiceSwitch.on
                        ? t('rooms.voice.host_on')
                        : t('rooms.voice.host_off')
                }
                on={voiceSwitch.on}
                busy={voiceSwitch.busy}
                disabled={disabled || voiceSwitch.busy}
                onToggle={voiceSwitch.toggle}
            />
            {voiceSwitch.error !== null && (
                <div className="pb-2">
                    <InputError message={voiceSwitch.error} />
                </div>
            )}
        </div>
    );
}
