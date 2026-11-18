import { ArrowRight, Laugh } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import {
    readTauntsHidden,
    shownTaunts,
    TAUNT_SENT_MS,
    tauntMoment,
    TAUNTS_UNSEEN,
    trayView,
    writeTauntsHidden,
} from '@/components/rooms/taunts';
import type { TauntDevice, TauntsSeen } from '@/components/rooms/taunts';
import { reducedMotion } from '@/components/rooms/motion-dom';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { store as throwTaunt } from '@/routes/rooms/taunts';
import { eventId } from '@/rooms/answer';
import type { ServerClock } from '@/rooms/clock';
import type {
    RoomPlayer,
    RoomState,
    RoomTauntReceived,
    RoomTauntShown,
} from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';
import { deviceStorage } from '@/rooms/sound';
import { sendTaunt } from '@/rooms/taunt';

/*
 * Taunts on the pages (docs/frontend.md rule 15 "Taunts", ADR 029
 * decisions 17–26, variant A «Zolaqda»): the tray beside the rivalry strip
 * and its popover, «Atıldı · −1 xal», the card over the target's strip,
 * the screen's cards over its own strip, the reveal's «−:count» and the
 * devices' own choice to hide them. They read the state's `taunts`,
 * `players`, the strip's rival and the prop `taunts` — never a verdict, a
 * solution or anyone's result before the reveal. Violet (`--taunt*`),
 * always with the emoji, the words and the icon; no sound.
 */

/** The prop `taunts` of `rooms/show` and `rooms/screen`: each key with its emoji's URL, in the tray's order. */
export type TauntList = { key: string; url: string }[];

function emojiOf(list: TauntList | null, key: string): string | null {
    return list?.find((taunt) => taunt.key === key)?.url ?? null;
}

/** A taunt's emoji: a Fluent file of the list, beside its words, never alone. */
function TauntEmoji({
    url,
    className,
}: {
    url: string | null;
    className: string;
}) {
    return url === null ? (
        <span aria-hidden className={cn('shrink-0', className)} />
    ) : (
        <img
            src={url}
            alt=""
            aria-hidden
            draggable={false}
            className={cn('shrink-0 select-none', className)}
        />
    );
}

/** A player's name as the state gives it, or «Oyunçu». */
function useNameOf(players: RoomPlayer[]): (id: string) => string {
    const { t } = useTranslation();

    return (id) =>
        players.find((player) => player.id === id)?.name ??
        t('rooms.show.nameless');
}

/** This device's choice to hide taunts, read once and remembered in `localStorage` (never sent). */
export function useTauntsHidden(
    device: TauntDevice,
): [boolean, (hidden: boolean) => void] {
    const [hidden, setHidden] = useState(() =>
        readTauntsHidden(deviceStorage(), device),
    );

    return [
        hidden,
        (next) => {
            writeTauntsHidden(deviceStorage(), device, next);
            setHidden(next);
        },
    ];
}

/**
 * The ids of the taunts the page saw arrive (`tauntMoment()`): a card pops
 * in for them; one the page opened on, or one of a state fetched again, is
 * drawn still.
 */
function useTauntArrivals(ids: string[]): ReadonlySet<string> {
    const seen = useRef<TauntsSeen>(TAUNTS_UNSEEN);
    const [arrived, setArrived] = useState<ReadonlySet<string>>(new Set());
    const key = ids.join(',');

    useEffect(() => {
        const moment = tauntMoment(
            seen.current,
            key === '' ? [] : key.split(','),
        );

        seen.current = moment.seen;

        if (moment.arrived.length > 0) {
            setArrived((before) => new Set([...before, ...moment.arrived]));
        }
    }, [key]);

    return arrived;
}

/**
 * The taunts a page shows now and which of them pop in: the state's list
 * at the server's clock as the page reads it every 200 ms
 * (`useServerNow()`, the page's `now`) and the page's arrivals.
 */
function useShownTaunts<T extends { id: string; sent_ms: number }>(
    list: T[] | null | undefined,
    now: number | null,
    hidden: boolean,
): { shown: T[]; arrived: ReadonlySet<string> } {
    const arrived = useTauntArrivals((list ?? []).map((taunt) => taunt.id));

    return { shown: shownTaunts(list, now, hidden), arrived };
}

/**
 * A received taunt over the strip on the target's phone (ADR 029 decision
 * 20): the strip's own height and width, never the tray, the round's head,
 * the time bar or the question; one card, or two as two rows, until
 * `sent_ms + show_ms` by the estimated server clock — then the strip
 * under it, which never moved, is back. Drawn inside the strip's own
 * `relative` box.
 */
export function TauntCover({
    received,
    players,
    list,
    now,
    hidden,
}: {
    received: RoomTauntReceived[] | null | undefined;
    players: RoomPlayer[];
    list: TauntList | null;
    /** The server's clock as the page estimates it (`useServerNow()`). */
    now: number | null;
    hidden: boolean;
}) {
    const { t } = useTranslation();
    const nameOf = useNameOf(players);
    const { shown, arrived } = useShownTaunts(received, now, hidden);
    const two = shown.length > 1;

    return (
        <div
            role="status"
            className={cn(
                'absolute inset-0 z-10 flex flex-col gap-1',
                shown.length > 0 ? 'bg-background' : 'pointer-events-none',
            )}
        >
            {shown.map((taunt) => (
                <div
                    key={taunt.id}
                    className={cn(
                        'flex min-h-0 flex-1 items-center gap-2.5 rounded-[14px] border-[1.5px] border-(--taunt) bg-[linear-gradient(var(--taunt-tint-strong),var(--taunt-tint)),var(--card)] shadow-lg',
                        two ? 'py-0.5 pr-2.5 pl-2' : 'py-1.5 pr-3 pl-2',
                        arrived.has(taunt.id) &&
                            'motion-safe:animate-room-taunt-in',
                    )}
                >
                    <TauntEmoji
                        url={emojiOf(list, taunt.taunt)}
                        className={two ? 'size-7.5' : 'size-10'}
                    />
                    <span
                        className={cn(
                            'flex min-w-0 leading-tight',
                            two
                                ? 'flex-row items-baseline gap-1.5'
                                : 'flex-col',
                        )}
                    >
                        <span
                            className={cn(
                                'truncate font-semibold text-(--taunt-text)',
                                two ? 'max-w-28 text-[0.71875rem]' : 'text-xs',
                            )}
                        >
                            {nameOf(taunt.from)}
                        </span>
                        <span
                            className={cn(
                                'font-bold',
                                two ? 'truncate text-sm' : 'text-base',
                            )}
                        >
                            {t(`rooms.taunts.say.${taunt.taunt}`)}
                        </span>
                    </span>
                </div>
            ))}
        </div>
    );
}

/**
 * The screen's taunts over its own strip (ADR 029 decision 21): each of
 * the state's `shown` for the same 4 s, one or two cards side by side —
 * the emoji, «:from → :to» and the phrase in the screen's locale. Drawn
 * inside the strip's own `relative` box; nothing on it writes or sounds.
 */
export function ScreenTauntCover({
    shown: list,
    players,
    taunts,
    now,
    hidden,
}: {
    shown: RoomTauntShown[] | null | undefined;
    players: RoomPlayer[];
    taunts: TauntList | null;
    /** The server's clock as the page estimates it (`useServerNow()`). */
    now: number | null;
    hidden: boolean;
}) {
    const { t } = useTranslation();
    const nameOf = useNameOf(players);
    const { shown, arrived } = useShownTaunts(list, now, hidden);

    return (
        <div
            role="status"
            className={cn(
                'absolute inset-0 z-10 flex gap-[1.2vh]',
                shown.length > 0 ? 'bg-background' : 'pointer-events-none',
            )}
        >
            {shown.map((taunt) => (
                <div
                    key={taunt.id}
                    className={cn(
                        'flex min-w-0 flex-1 items-center gap-[1.4vh] rounded-[2vh] border-2 border-(--taunt) bg-[linear-gradient(var(--taunt-tint-strong),var(--taunt-tint)),var(--card)] py-[0.8vh] pr-[2vh] pl-[1.2vh] shadow-lg',
                        arrived.has(taunt.id) &&
                            'motion-safe:animate-room-taunt-in',
                    )}
                >
                    <TauntEmoji
                        url={emojiOf(taunts, taunt.taunt)}
                        className="size-[6vh]"
                    />
                    <span className="flex min-w-0 flex-col leading-tight">
                        <span className="flex min-w-0 items-center gap-[0.6vh] text-[max(0.75rem,2.1vh)] font-semibold text-(--taunt-text)">
                            <span className="truncate">
                                {nameOf(taunt.from)}
                            </span>
                            <ArrowRight
                                aria-label="→"
                                className="size-[1em] shrink-0"
                            />
                            <span className="truncate">{nameOf(taunt.to)}</span>
                        </span>
                        <span className="truncate text-[max(1rem,3vh)] font-bold">
                            {t(`rooms.taunts.say.${taunt.taunt}`)}
                        </span>
                    </span>
                </div>
            ))}
        </div>
    );
}

/**
 * The screen's «Atmacalar» in its top bar, before the sound (ADR 029
 * decision 21): a toggle (`aria-pressed`: taunts are shown) that hides
 * them on this screen only, remembered in `localStorage`.
 */
export function ScreenTauntsToggle({
    hidden,
    onChange,
}: {
    hidden: boolean;
    onChange: (hidden: boolean) => void;
}) {
    const { t } = useTranslation();

    return (
        <button
            type="button"
            aria-pressed={!hidden}
            onClick={() => onChange(!hidden)}
            className={cn(
                'inline-flex h-11 min-w-11 items-center justify-center gap-2 rounded-md border bg-background px-3 text-sm font-medium shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                hidden
                    ? 'text-muted-foreground'
                    : 'border-(--taunt) text-(--taunt-text)',
            )}
        >
            <Laugh aria-hidden className="size-4" />
            {t('rooms.taunts.switch')}
        </button>
    );
}

/**
 * «−:count» with the taunt's icon (ADR 029 decision 6): the taunts counted
 * at this close, beside a round's result in «Sıralama» and in the race
 * lanes; «Atmacalara :count xal» to a screen reader.
 */
export function SpentMark({
    count,
    className,
}: {
    count: number;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <span
            aria-label={t('rooms.taunts.spent_label', { count })}
            className={cn(
                'inline-flex items-center gap-0.5 font-bold whitespace-nowrap text-(--taunt-text) tabular-nums',
                className,
            )}
        >
            <Laugh aria-hidden className="size-[0.95em]" />
            <span aria-hidden>−{count}</span>
        </span>
    );
}

/** «Atmaca: −:count xal» under the thrower's own verdict in the reveal. */
export function SpentLine({ count }: { count: number }) {
    const { t } = useTranslation();

    return (
        <span className="flex items-center gap-1 font-semibold text-(--taunt-text)">
            <Laugh aria-hidden className="size-3.5" />
            {t('rooms.taunts.spent', { count })}
        </span>
    );
}

/** The players' costs counted at this close, by row (the state's `reveal.spent`). */
export function spentOf(state: RoomState): Map<string, number> {
    const reveal =
        state.round !== null && state.round.phase === 'reveal'
            ? state.round.reveal
            : null;

    return new Map(
        (reveal?.spent ?? []).map((spent) => [spent.player_id, spent.count]),
    );
}

type Refusal = 'late' | 'off' | 'cooling' | 'no_points';

/**
 * The tray (ADR 029 decisions 17–19): a 44px round button at the right
 * end of the strip's row and, open, a popover under the strip, the strip's
 * width, over the thrower's own round head and question — «Kimə: :name»,
 * the price, the balance or the reason, the ten taunts in two columns and
 * «Mənə atılanları gizlət». A tap throws at the strip's rival; on
 * `accepted` «Atıldı · −1 xal» lies over the strip's gap row for 2 s and
 * «−1» rises once from the button (not under reduced motion), and the page
 * asks for the state; a refusal says why in one line. Drawn inside the
 * strip's row, which is `relative`.
 */
export function TauntTray({
    roomId,
    state,
    rival,
    list,
    clock,
    now,
    hidden,
    onHiddenChange,
    onThrown,
}: {
    roomId: string;
    state: RoomState;
    /** The strip's rival on the right: whom the tray throws at. */
    rival: { id: string; name: string | null } | null;
    list: TauntList | null;
    /** The page's estimate of the server's clock: a taunt's event id. */
    clock: ServerClock;
    /** The server's clock as the page reads it every 200 ms (`useServerNow()`). */
    now: number | null;
    hidden: boolean;
    onHiddenChange: (hidden: boolean) => void;
    /** After a receipt: the page asks for the state now. */
    onThrown: () => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [refusal, setRefusal] = useState<Refusal | null>(null);
    const [sent, setSent] = useState<{ key: string; at: number } | null>(null);
    const button = useRef<HTMLButtonElement>(null);
    const popover = useRef<HTMLDivElement>(null);
    const view = trayView(state, rival?.id ?? null, now);
    const cooling = view.kind === 'tray' ? view.cooling : null;
    const readyMs = state.taunts?.ready_ms ?? null;
    // The cooldown's length as the page first saw it: the ring fills from there.
    const [cool, setCool] = useState<{ ready: number; total: number } | null>(
        null,
    );

    useEffect(() => {
        if (readyMs !== null && now !== null && cool?.ready !== readyMs) {
            setCool({ ready: readyMs, total: Math.max(1, readyMs - now) });
        }
    }, [readyMs, now, cool]);

    useEffect(() => {
        if (sent === null) {
            return;
        }

        const timer = window.setTimeout(() => setSent(null), TAUNT_SENT_MS);

        return () => window.clearTimeout(timer);
    }, [sent]);

    // A tap outside, or Escape, closes it.
    useEffect(() => {
        if (!open) {
            return;
        }

        const outside = (event: PointerEvent) => {
            const target = event.target as Node | null;

            if (
                target !== null &&
                !popover.current?.contains(target) &&
                !button.current?.contains(target)
            ) {
                setOpen(false);
            }
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                button.current?.focus();
            }
        };

        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    if (view.kind === 'none' || rival === null) {
        return null;
    }

    const left =
        readyMs === null || now === null ? 0 : Math.max(0, readyMs - now);
    const fraction =
        cool === null || cool.ready !== readyMs
            ? 1
            : Math.min(1, left / cool.total);
    const canThrow = view.reason === null && !sending;
    const muted = view.reason === 'first_round' || view.reason === 'no_points';
    const line: ReactNode =
        refusal !== null && refusal !== 'cooling' && refusal !== 'no_points'
            ? t(`rooms.taunts.${refusal}`)
            : view.reason === 'first_round'
              ? t('rooms.taunts.first_round')
              : view.reason === 'no_points'
                ? t('rooms.taunts.no_points')
                : null;

    const send = (key: string) => {
        if (!canThrow) {
            return;
        }

        setSending(true);
        setRefusal(null);
        void sendTaunt({
            taunt: {
                event_id: eventId(clock.now() ?? 0),
                target_id: rival.id,
                taunt: key,
            },
            post: (taunt) => roomRequest('POST', throwTaunt.url(roomId), taunt),
            wait: (ms) =>
                new Promise((resolve) => window.setTimeout(resolve, ms)),
        })
            .then((outcome) => {
                if (outcome.kind !== 'receipt') {
                    setRefusal('late');

                    return;
                }

                const status = outcome.receipt.status;

                if (status === 'accepted' || status === 'duplicate') {
                    setOpen(false);
                    setSent({ key, at: Date.now() });
                } else {
                    setRefusal(status);
                }
            })
            .finally(() => {
                setSending(false);
                onThrown();
            });
    };

    return (
        <>
            <button
                ref={button}
                type="button"
                aria-label={t('rooms.taunts.tray')}
                aria-expanded={open}
                onClick={() => {
                    setRefusal(null);
                    setOpen((was) => !was);
                }}
                className={cn(
                    'relative grid size-11 shrink-0 place-items-center rounded-full border-[1.5px] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    cooling !== null
                        ? 'border-transparent bg-transparent'
                        : muted
                          ? 'border-border bg-muted text-muted-foreground'
                          : open
                            ? 'border-(--taunt) bg-(--taunt) text-white'
                            : 'border-(--taunt) bg-(--taunt-tint) text-(--taunt-text)',
                )}
            >
                {cooling !== null ? (
                    <span
                        aria-hidden
                        className="grid size-10 place-items-center rounded-full text-sm font-bold text-(--taunt-text) tabular-nums"
                        style={{
                            background: `radial-gradient(circle, var(--card) 0 15px, transparent 15.5px), conic-gradient(var(--taunt) ${(1 - fraction) * 360}deg, var(--border) 0)`,
                        }}
                    >
                        {cooling}
                    </span>
                ) : (
                    <Laugh aria-hidden className="size-5.5" />
                )}
                {sent !== null && !reducedMotion() && (
                    <span
                        aria-hidden
                        className="pointer-events-none absolute -top-1 right-0 text-[0.9375rem] font-extrabold text-(--taunt-text) opacity-0 motion-safe:animate-room-taunt-rise"
                    >
                        −1
                    </span>
                )}
            </button>

            {sent !== null && (
                <span className="pointer-events-none absolute right-13 bottom-1.5 left-0 z-20 flex justify-center">
                    <span className="inline-flex items-center gap-1.5 rounded-full bg-(--taunt) py-0.5 pr-2.5 pl-1 text-[0.78125rem] font-bold text-white ring-2 ring-card">
                        <TauntEmoji
                            url={emojiOf(list, sent.key)}
                            className="size-4.5"
                        />
                        {t('rooms.taunts.sent')}
                    </span>
                </span>
            )}

            {open && (
                <div
                    ref={popover}
                    className="absolute top-[calc(100%+6px)] right-0 left-0 z-30 flex flex-col gap-2 rounded-2xl border bg-card p-2.5 shadow-2xl"
                >
                    <div className="flex items-center justify-between gap-2 text-[0.84375rem]">
                        <span className="truncate">
                            {t('rooms.taunts.to', {
                                name: rival.name ?? t('rooms.show.nameless'),
                            })}
                        </span>
                        <span className="inline-flex shrink-0 items-center gap-1 text-[0.78125rem] font-bold text-(--taunt-text)">
                            <Laugh aria-hidden className="size-3.5" />
                            {t('rooms.taunts.price')}
                        </span>
                    </div>
                    {line !== null ? (
                        <p className="rounded-[10px] bg-muted px-2.5 py-2 text-[0.78125rem] font-semibold">
                            {line}
                        </p>
                    ) : (
                        <p className="flex flex-wrap justify-between gap-x-2 text-[0.78125rem] text-muted-foreground">
                            <span>
                                {t('rooms.taunts.balance', {
                                    count: view.balance,
                                })}
                            </span>
                            {cooling !== null && (
                                <span className="font-semibold text-(--taunt-text)">
                                    {t('rooms.taunts.cooling', {
                                        seconds: cooling,
                                    })}
                                </span>
                            )}
                        </p>
                    )}
                    <div className="grid grid-cols-2 gap-1.5">
                        {(list ?? []).map((taunt) => (
                            <button
                                key={taunt.key}
                                type="button"
                                disabled={!canThrow}
                                onClick={() => send(taunt.key)}
                                className="flex min-h-12 items-center gap-2 rounded-xl border bg-card py-1.5 pr-2.5 pl-2 text-left text-[0.8125rem] leading-tight font-semibold outline-none hover:border-(--taunt) hover:bg-(--taunt-tint) focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-default disabled:opacity-45 disabled:grayscale-[0.6] disabled:hover:border-border disabled:hover:bg-card"
                            >
                                <TauntEmoji
                                    url={taunt.url}
                                    className="size-7"
                                />
                                {t(`rooms.taunts.say.${taunt.key}`)}
                            </button>
                        ))}
                    </div>
                    <button
                        type="button"
                        role="switch"
                        aria-checked={hidden}
                        onClick={() => onHiddenChange(!hidden)}
                        className="flex min-h-11 items-center gap-2.5 text-left text-[0.8125rem] outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <span
                            aria-hidden
                            className={cn(
                                'relative h-5 w-9 shrink-0 rounded-full transition-colors motion-reduce:transition-none',
                                hidden ? 'bg-primary' : 'bg-input',
                            )}
                        >
                            <span
                                className={cn(
                                    'absolute top-0.5 left-0.5 size-4 rounded-full bg-background shadow-sm transition-transform motion-reduce:transition-none',
                                    hidden && 'translate-x-4',
                                )}
                            />
                        </span>
                        {t('rooms.taunts.hide')}
                    </button>
                </div>
            )}
        </>
    );
}
