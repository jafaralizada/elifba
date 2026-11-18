import { Link, router } from '@inertiajs/react';
import { ChevronRight, Ellipsis, LogOut, Monitor, X } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { RoomConfirm } from '@/components/rooms/room-confirm';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { close, screen, update } from '@/routes/rooms';
import { update as setPlays } from '@/routes/rooms/plays';
import type { RoomState } from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';

/** A bottom sheet below `sm`, a dialog from there: the classes `DialogContent` takes for it. */
export const SHEET =
    'top-auto bottom-0 left-0 max-h-[92dvh] max-w-none translate-x-0 translate-y-0 gap-0 rounded-none rounded-t-2xl border-x-0 border-b-0 p-0 motion-reduce:animate-none data-[state=closed]:zoom-out-100 data-[state=open]:zoom-in-100 sm:top-[50%] sm:bottom-auto sm:left-[50%] sm:max-w-md sm:translate-x-[-50%] sm:translate-y-[-50%] sm:rounded-2xl sm:border';

/**
 * «Otağın idarəsi» (docs/frontend.md rule 15 "Games first", ADR 026
 * decision 16): the host's tools behind one 44px `Ellipsis` button, a
 * sheet of 52px rows — «Mən də oynayıram» and «Kilidlə» as switches with
 * their lines, «Böyük ekran» (a link: the game is chosen and started from
 * there too) and «Otağı bağla», which asks first. Not offered while a
 * game runs: the round has «Oyunu bitir» instead, and the two never stand
 * on one screen.
 */
export function RoomTools({
    roomId,
    state,
    onChanged,
}: {
    roomId: string;
    state: RoomState;
    onChanged: () => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState<'plays' | 'lock' | null>(null);
    const [error, setError] = useState<string | null>(null);
    const mine = state.players.find(
        (player) => player.id === state.me.player_id,
    );
    const plays = mine?.present ?? false;
    const locked = state.room.locked;

    const togglePlays = () => {
        setBusy('plays');
        setError(null);
        void roomRequest('PUT', setPlays.url(roomId), { plays: !plays })
            .then((reply) => {
                if (reply.status === 422) {
                    const body = reply.body as {
                        errors?: Record<string, string[]>;
                    } | null;

                    setError(
                        Object.values(body?.errors ?? {})[0]?.[0] ??
                            t('rooms.setup.change_failed'),
                    );
                } else if (reply.status !== 204) {
                    setError(t('rooms.setup.change_failed'));
                }
            })
            .catch(() => setError(t('rooms.setup.change_failed')))
            .finally(() => {
                setBusy(null);
                onChanged();
            });
    };

    const toggleLock = () => {
        setBusy('lock');
        setError(null);
        router.patch(
            update.url(roomId),
            { locked: !locked },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => setError(t('rooms.setup.change_failed')),
                onFinish: () => {
                    setBusy(null);
                    onChanged();
                },
            },
        );
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setError(null);
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    className="size-11 shrink-0 rounded-full"
                    aria-label={t('rooms.show.tools')}
                >
                    <Ellipsis className="size-5" />
                </Button>
            </DialogTrigger>
            <DialogContent
                showCloseButton={false}
                aria-describedby={undefined}
                className={SHEET}
            >
                <div className="flex items-center gap-3 border-b px-4 py-2">
                    <DialogTitle className="min-w-0 flex-1 text-lg">
                        {t('rooms.show.tools')}
                    </DialogTitle>
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
                <div className="flex flex-col divide-y px-4 pb-[max(env(safe-area-inset-bottom),0.75rem)]">
                    <SwitchRow
                        label={t('rooms.tools.plays')}
                        line={t('rooms.tools.plays_line')}
                        on={plays}
                        busy={busy === 'plays'}
                        disabled={busy !== null}
                        onToggle={togglePlays}
                    />
                    <SwitchRow
                        label={t('rooms.show.lock')}
                        line={
                            locked
                                ? t('rooms.show.locked')
                                : t('rooms.tools.lock_line')
                        }
                        on={locked}
                        busy={busy === 'lock'}
                        disabled={busy !== null}
                        onToggle={toggleLock}
                    />
                    <Link
                        href={screen(roomId)}
                        className="flex min-h-13 items-center gap-3 py-3 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        <Monitor aria-hidden className="size-5 shrink-0" />
                        <RowText
                            label={t('rooms.show.screen')}
                            line={t('rooms.show.screen_hint')}
                        />
                        <ChevronRight
                            aria-hidden
                            className="size-5 shrink-0 text-muted-foreground"
                        />
                    </Link>
                    <RoomConfirm
                        trigger={
                            <button
                                type="button"
                                className="flex min-h-13 items-center gap-3 py-3 text-left font-medium text-red-600 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:text-red-400"
                            >
                                <LogOut
                                    aria-hidden
                                    className="size-5 shrink-0"
                                />
                                {t('rooms.show.close')}
                            </button>
                        }
                        title={t('rooms.show.close_title')}
                        description={t('rooms.show.close_description')}
                        confirmLabel={t('rooms.show.close')}
                        destructive
                        form={close.form(roomId)}
                    />
                    {error !== null && (
                        <div className="py-2">
                            <InputError message={error} />
                        </div>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function RowText({ label, line }: { label: string; line: string }) {
    return (
        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
            <span className="font-medium">{label}</span>
            <span className="text-[0.8125rem] leading-snug text-muted-foreground">
                {line}
            </span>
        </span>
    );
}

/** A 52px row that is one switch: its label, its line and the track. */
function SwitchRow({
    label,
    line,
    on,
    busy,
    disabled,
    onToggle,
}: {
    label: string;
    line: string;
    on: boolean;
    busy: boolean;
    disabled: boolean;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={on}
            disabled={disabled}
            onClick={onToggle}
            className="flex min-h-13 items-center gap-3 py-3 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-wait"
        >
            <RowText label={label} line={line} />
            {busy ? <Spinner /> : <Track on={on} />}
        </button>
    );
}

function Track({ on }: { on: boolean }): ReactNode {
    return (
        <span
            aria-hidden
            className={cn(
                'relative h-7 w-12 shrink-0 rounded-full transition-colors motion-reduce:transition-none',
                on ? 'bg-primary' : 'bg-input',
            )}
        >
            <span
                className={cn(
                    'absolute top-0.5 left-0.5 size-6 rounded-full bg-background shadow-sm transition-transform motion-reduce:transition-none',
                    on && 'translate-x-5',
                )}
            />
        </span>
    );
}
