import { Link } from '@inertiajs/react';
import {
    Ban,
    Check,
    HeadphoneOff,
    Headphones,
    Hourglass,
    LogOut,
    Mic,
    MicOff,
    Pointer,
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
    VoiceTrouble,
    VoiceView,
} from '@/rooms/voice-view';

/**
 * The live voice on a room page (docs/frontend.md rule 15 "Live voice", ADR
 * 031 decision 34 as amended 2026-11-19): one round 44px button in the
 * page's head — the room's header outside a round, the round's head beside
 * «Oyun səsləri» in one — whose icon says the viewer's standing, and the
 * panel «Canlı səs» it opens, where everything of the voice is done: the
 * join, the microphone, who is in, «Səsdən çıx» and the host's tools. A
 * note stands over the page for a moment. It draws `voice.view` and nothing
 * it worked out itself, and nothing of it is there while the voice is off.
 * The sentence before a first join (`VoiceConsent`) is here too. From ADR
 * 031 as amended 2026-11-19 the page is in the voice by itself, so the
 * button's first look is «in the voice» for an account that agreed; its
 * dot asks for a tap where the browser holds the voice back.
 */
export function VoiceButton({ voice }: { voice: RoomVoice }) {
    const { view, note } = voice;

    if (view === null && note === null) {
        return null;
    }

    return (
        <>
            {view !== null && (
                <VoiceIcon view={view} onOpen={() => voice.setSheet(true)} />
            )}
            {note !== null && <VoiceNote note={note} />}
            {view !== null && (
                <>
                    <VoiceConsent voice={voice} />
                    <VoicePanel voice={voice} />
                </>
            )}
        </>
    );
}

/** The voice's filled button and pill: the fill in the voice's colour, its text readable on it. */
const FILLED =
    'border-(--voice) bg-(--voice) text-(--voice-on) hover:bg-(--voice)/90 hover:text-(--voice-on)';

const TINTED = 'border-(--voice)/40 bg-(--voice-tint) text-(--voice-text)';

/** The head's button: the standing as an icon, a ring while the microphone is open, a dot or a count where something waits for a tap. */
function VoiceIcon({ view, onOpen }: { view: VoiceView; onOpen: () => void }) {
    const { t } = useTranslation();
    const bar = view.bar;
    const waiting = view.host
        ? view.rows.filter((row) => row.state === 'waiting').length
        : 0;
    let icon: ReactNode;
    let look: string;
    let label: string;
    let ring = false;
    let dot = false;

    switch (bar.form) {
        case 'insecure':
            icon = <HeadphoneOff className="size-5" />;
            look = 'border-transparent bg-muted text-muted-foreground';
            label = t('rooms.voice.errors.insecure');
            break;
        case 'waiting':
            icon = <Hourglass className="size-5" />;
            look = TINTED;
            label = t('rooms.voice.state.waiting');
            break;
        case 'invited':
            icon = bar.joining ? (
                <Spinner className="size-5" />
            ) : (
                <Headphones className="size-5" />
            );
            look = cn(TINTED, 'border-(--voice)');
            label = t('rooms.voice.invite');
            dot = true;
            break;
        case 'trouble':
            icon =
                bar.trouble === 'reconnecting' ? (
                    <Spinner className="size-5" />
                ) : (
                    <HeadphoneOff className="size-5" />
                );
            look =
                bar.trouble === 'reconnecting'
                    ? TINTED
                    : 'border-transparent bg-muted text-muted-foreground';
            label = troubleLine(bar.trouble, t);
            dot = bar.trouble !== 'reconnecting';
            break;
        case 'in':
            icon =
                bar.mic === 'opening' ? (
                    <Spinner className="size-5" />
                ) : bar.mic === 'on' ? (
                    <Mic className="size-5" />
                ) : (
                    <MicOff className="size-5" />
                );
            look =
                bar.mic === 'on'
                    ? FILLED
                    : bar.mic === 'muted'
                      ? 'border-dashed border-muted-foreground/50 bg-background text-muted-foreground'
                      : cn(
                            TINTED,
                            (bar.speaker.kind !== 'none' || bar.held) &&
                                'border-(--voice)',
                        );
            label = bar.held
                ? t('rooms.voice.tap_to_hear')
                : bar.mic === 'on'
                  ? t('rooms.voice.state.mic')
                  : bar.mic === 'muted'
                    ? t('rooms.voice.state.muted')
                    : t('rooms.voice.state.listening');
            ring = bar.mic === 'on';
            // The browser holds the voice back until a tap anywhere (ADR 031 decision 42).
            dot = bar.held;
            break;
    }

    return (
        <button
            type="button"
            data-voice-button={bar.form === 'in' ? bar.mic : bar.form}
            aria-haspopup="dialog"
            aria-label={`${t('rooms.voice.title')}: ${label}`}
            title={t('rooms.voice.title')}
            onClick={onOpen}
            className={cn(
                'relative inline-flex size-11 shrink-0 items-center justify-center rounded-full border-[1.5px] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                look,
            )}
        >
            {ring && (
                <span
                    aria-hidden
                    className="absolute -inset-[3px] rounded-full border-2 border-(--voice) opacity-60 motion-safe:animate-ping"
                />
            )}
            <span aria-hidden className="contents">
                {icon}
            </span>
            {waiting > 0 ? (
                <span
                    aria-hidden
                    className="absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-(--voice) px-1 text-[0.6875rem] font-bold text-(--voice-on) ring-2 ring-background"
                >
                    {waiting}
                </span>
            ) : (
                dot && (
                    <span
                        aria-hidden
                        className="absolute top-0 right-0 size-3 rounded-full bg-(--voice) ring-2 ring-background"
                    />
                )
            )}
        </button>
    );
}

function troubleLine(
    trouble: VoiceTrouble | 'reconnecting',
    t: (key: string) => string,
): string {
    switch (trouble) {
        case 'reconnecting':
            return t('rooms.voice.reconnecting');
        case 'elsewhere':
            return t('rooms.voice.elsewhere');
        case 'busy':
            return t('rooms.voice.errors.busy');
        default:
            return t('rooms.voice.errors.unreachable');
    }
}

const NOTE_ICONS: Record<RoomVoiceNote, typeof Mic> = {
    hidden_mic: MicOff,
    muted_you: MicOff,
    turned_off: HeadphoneOff,
    'errors.denied': MicOff,
    'errors.no_mic': MicOff,
};

/** A note for its moment (`NOTE_MS`), over the page under the game frame's top bar (ADR 032). */
function VoiceNote({ note }: { note: RoomVoiceNote }) {
    const { t } = useTranslation();
    const Icon = NOTE_ICONS[note];

    return (
        <p
            role="status"
            className="fixed inset-x-4 top-[calc(env(safe-area-inset-top)+4.25rem)] z-50 mx-auto flex max-w-md items-center gap-2.5 rounded-2xl border bg-background px-4 py-2.5 text-[0.8125rem] leading-snug shadow-lg"
        >
            <Icon aria-hidden className="size-4 shrink-0 text-(--voice-text)" />
            {t(`rooms.voice.${note}`)}
        </p>
    );
}

/**
 * The microphone in the panel, its strongest button, saying what a tap
 * does: «Mikrofonu aç», filled in the voice's colour, with the muted line
 * to always allow the site under it while the browser would ask (ADR 031
 * decision 36); «Mikrofonu söndür» with a pulsing dot while open;
 * «Susdurulub», dashed, with why.
 */
function MicToggle({
    mic,
    alwaysAllow,
    onTap,
}: {
    mic: 'off' | 'opening' | 'on' | 'muted';
    alwaysAllow: boolean;
    onTap: () => void;
}) {
    const { t } = useTranslation();
    const open = mic === 'on';

    if (mic === 'muted') {
        return (
            <div className="flex flex-col items-center gap-1.5">
                <span
                    data-voice-mic="muted"
                    className="inline-flex h-13 w-full items-center justify-center gap-2 rounded-full border-[1.5px] border-dashed text-base font-semibold text-muted-foreground"
                >
                    <MicOff aria-hidden className="size-5" />
                    {t('rooms.voice.mic.muted')}
                </span>
                <p className="text-center text-[0.8125rem] text-muted-foreground">
                    {t('rooms.voice.muted_you')}
                </p>
            </div>
        );
    }

    const button = (
        <button
            type="button"
            data-voice-mic={mic}
            disabled={mic === 'opening'}
            onClick={onTap}
            className={cn(
                'inline-flex h-13 w-full items-center justify-center gap-2.5 rounded-full border-[1.5px] text-base font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait',
                open
                    ? 'border-(--voice) bg-(--voice-tint) text-(--voice-text)'
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
            ) : (
                <Mic aria-hidden className="size-5" />
            )}
            {open ? t('rooms.voice.mic.close') : t('rooms.voice.mic.open')}
        </button>
    );

    if (!alwaysAllow) {
        return button;
    }

    return (
        <div className="flex flex-col items-center gap-1.5">
            {button}
            <p
                data-voice-always-allow
                className="text-center text-[0.8125rem] leading-snug text-muted-foreground"
            >
                {t('rooms.voice.always_allow')}
            </p>
        </div>
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
 * The sentence before an account's first join (ADR 031 decision 14): «Canlı
 * səs», `rooms.voice.agreement` in a tinted box, the three notes, «Razıyam,
 * qoşul» and «İndi yox», and the way to `/privacy`'s part «Canlı səs». A
 * sheet below `sm`, a dialog from there. From 2026-11-19 it opens by itself
 * once outside a round (decision 41), later joins are the page's own, and
 * closing it without «Razıyam, qoşul» is «İndi yox», remembered for the
 * room on this device.
 */
function VoiceConsent({ voice }: { voice: RoomVoice }) {
    const { t } = useTranslation();
    const notes = [
        ['mic', MicOff],
        ['host', Shield],
        ['near', Headphones],
    ] as const;

    return (
        <Dialog
            open={voice.consent}
            onOpenChange={(open) => {
                if (!open) {
                    voice.dismissConsent();
                }
            }}
        >
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
 * The panel «Canlı səs» the head's button opens (rule 15 "The panel «Canlı
 * səs»"), in every standing: the invitation with «Səsə qoşul», the wait,
 * the trouble with its way back, or — in the voice — who speaks, the
 * microphone, a 52px row for the host and each present player (the state's
 * icon on a disc, the name with «siz» or «aparıcı», the state in words,
 * those who speak first) and «Səsdən çıx»; for the host the rows and one
 * 40px button on each other row in every standing, and «Canlı səsi söndür»
 * at the end. No confirmation: each is undone by its opposite.
 */
function VoicePanel({ voice }: { voice: RoomVoice }) {
    const { t } = useTranslation();
    const view = voice.view;

    if (view === null) {
        return null;
    }

    const bar = view.bar;
    const inVoice = bar.form === 'in';
    const rows = inVoice || view.host;

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
                        {inVoice && (
                            <span className="text-[0.8125rem] text-muted-foreground">
                                {t('rooms.voice.count', { count: bar.count })}
                            </span>
                        )}
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
                <div className="flex flex-col gap-3 px-4 pt-4 pb-1">
                    <PanelStanding bar={bar} voice={voice} />
                </div>
                {rows && (
                    <div className="flex max-h-[45dvh] flex-col overflow-y-auto px-4">
                        <h3 className="sr-only">{t('rooms.voice.people')}</h3>
                        <ul className="flex flex-col divide-y">
                            {view.rows.map((row) => (
                                <PersonRow
                                    key={row.id}
                                    row={row}
                                    pending={voice.pending === row.id}
                                    onAct={(action) =>
                                        voice.act(row.id, action)
                                    }
                                />
                            ))}
                        </ul>
                    </div>
                )}
                <div className="flex flex-col gap-2 border-t px-4 pt-3 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                    {voice.failed && (
                        <InputError message={t('rooms.setup.change_failed')} />
                    )}
                    {inVoice && (
                        <Button
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={voice.leave}
                        >
                            <LogOut />
                            {t('rooms.voice.leave')}
                        </Button>
                    )}
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
                    {!inVoice && !view.host && (
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-11"
                            onClick={voice.notNow}
                        >
                            {t('rooms.voice.not_now')}
                        </Button>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

/** The panel's first part: what the viewer can do in the voice now. */
function PanelStanding({
    bar,
    voice,
}: {
    bar: VoiceBarForm;
    voice: RoomVoice;
}) {
    const { t } = useTranslation();

    switch (bar.form) {
        case 'insecure':
            return (
                <Line icon={HeadphoneOff}>
                    {t('rooms.voice.errors.insecure')}
                </Line>
            );
        case 'waiting':
            return (
                <Line icon={Hourglass} voiced>
                    {t('rooms.voice.waiting_you')}
                </Line>
            );
        case 'invited':
            return (
                <>
                    <span className="flex flex-col gap-0.5">
                        <span className="text-[0.9375rem] font-semibold">
                            {t('rooms.voice.invite')}
                        </span>
                        <span className="text-[0.8125rem] text-muted-foreground">
                            {t('rooms.voice.invite_line')}
                        </span>
                    </span>
                    <Button
                        type="button"
                        className={cn('h-13 rounded-full text-base', FILLED)}
                        disabled={bar.joining}
                        onClick={voice.join}
                    >
                        {bar.joining ? <Spinner /> : <Headphones />}
                        {t('rooms.voice.join')}
                    </Button>
                </>
            );
        case 'trouble': {
            if (bar.trouble === 'reconnecting') {
                return (
                    <p
                        role="status"
                        className="flex items-center gap-2.5 text-sm"
                    >
                        <Spinner className="size-5 shrink-0 text-(--voice-text)" />
                        {t('rooms.voice.reconnecting')}
                    </p>
                );
            }

            // «Yenidən cəhd et» for an unreachable or a full voice: the page never tries again by itself (ADR 031 decision 38).
            const action =
                bar.trouble === 'elsewhere'
                    ? t('rooms.voice.here')
                    : bar.trouble === 'dropped'
                      ? t('rooms.voice.rejoin')
                      : t('rooms.voice.retry');

            return (
                <>
                    <Line
                        icon={
                            bar.trouble === 'elsewhere'
                                ? Smartphone
                                : HeadphoneOff
                        }
                    >
                        {troubleLine(bar.trouble, t)}
                    </Line>
                    <Button
                        type="button"
                        className={cn('h-12 rounded-full text-base', FILLED)}
                        onClick={voice.retry}
                    >
                        {action}
                    </Button>
                </>
            );
        }
        case 'in':
            return (
                <>
                    {bar.held ? (
                        <p
                            role="status"
                            data-voice-held
                            className="flex min-h-6 items-center gap-2.5 text-[0.9375rem] font-semibold"
                        >
                            <Pointer
                                aria-hidden
                                className="size-5 shrink-0 text-(--voice-text)"
                            />
                            {t('rooms.voice.tap_to_hear')}
                        </p>
                    ) : (
                        <p className="flex min-h-6 items-center gap-2.5 text-[0.9375rem] font-semibold">
                            {bar.speaker.kind === 'none' ? (
                                <Headphones
                                    aria-hidden
                                    className="size-5 shrink-0 text-(--voice-text)"
                                />
                            ) : (
                                <SpeakingBars className="text-[1.25rem] text-(--voice-text)" />
                            )}
                            <span className="min-w-0 truncate">
                                <SpeakerLine speaker={bar.speaker} />
                            </span>
                        </p>
                    )}
                    <MicToggle
                        mic={bar.mic}
                        alwaysAllow={bar.alwaysAllow}
                        onTap={voice.tapMic}
                    />
                </>
            );
    }
}

function Line({
    icon: Icon,
    voiced = false,
    children,
}: {
    icon: typeof Mic;
    voiced?: boolean;
    children: ReactNode;
}) {
    return (
        <p
            role="status"
            className="flex items-start gap-2.5 text-[0.9375rem] leading-snug"
        >
            <Icon
                aria-hidden
                className={cn(
                    'mt-0.5 size-5 shrink-0',
                    voiced ? 'text-(--voice-text)' : 'text-muted-foreground',
                )}
            />
            {children}
        </p>
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
                              t('rooms.setup.change_failed'))
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
 * is on (rule 15 "The host turns it off and on"; on from the room's
 * opening since ADR 031 as amended 2026-11-19): `Headphones`, the switch
 * and its line — what turning it on means, or that turning it off stops it
 * for everyone at once. Turning it on is never refused: the limit on voice
 * rooms is the ticket's.
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
