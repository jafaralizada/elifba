import { QrCode } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/** Where a phone joins the open room, from the server (`RoomJoinLink`). */
export type JoinLink = { url: string; entry_url: string };

/** A room code as it is read aloud: two groups of three digits. */
export function spacedCode(code: string): string {
    return code.replace(/^(\d{3})(\d{3})$/, '$1 $2');
}

/** The address of the code field without its scheme, as a person types it. */
export function typedAddress(url: string): string {
    return url.replace(/^https?:\/\//, '');
}

/**
 * The room's QR, always dark on white so a camera reads it in both
 * themes. Its link comes from the server and holds the code only.
 */
export function RoomQr({
    url,
    size,
    fluid = false,
    className,
}: {
    url: string;
    /** Its side in pixels; with `fluid`, the width of the box drawn around it instead. */
    size: number;
    fluid?: boolean;
    className?: string;
}) {
    const { t } = useTranslation();

    return (
        <div
            className={cn(
                'inline-flex shrink-0 rounded-xl bg-white p-2 shadow-xs ring-1 ring-black/5',
                className,
            )}
        >
            <QRCodeSVG
                value={url}
                size={size}
                level="M"
                marginSize={1}
                bgColor="#ffffff"
                fgColor="#000000"
                title={t('rooms.show.qr')}
                className={fluid ? 'block aspect-square size-full' : 'block'}
                style={fluid ? undefined : { width: size, height: size }}
            />
        </div>
    );
}

/**
 * The lobby's code card (docs/frontend.md rule 15, "The lobby"): the QR
 * beside the code and how to join with either.
 */
export function CodeCard({
    code,
    join,
}: {
    code: string;
    join: JoinLink | null;
}) {
    const { t } = useTranslation();

    return (
        <section
            aria-labelledby="room-code"
            className="flex items-center gap-4 rounded-2xl border bg-card p-4"
        >
            {join !== null && <RoomQr url={join.url} size={120} />}
            <div className="flex min-w-0 flex-col gap-1">
                <span id="room-code" className="text-sm text-muted-foreground">
                    {t('rooms.show.code')}
                </span>
                <span className="text-[2rem] leading-none font-bold tracking-wider whitespace-nowrap tabular-nums">
                    {spacedCode(code)}
                </span>
                {join !== null && (
                    <span className="mt-1 text-xs text-balance text-muted-foreground">
                        {t('rooms.screen.join', {
                            address: typedAddress(join.entry_url),
                        })}
                    </span>
                )}
            </div>
        </section>
    );
}

/**
 * The code in a round's header: a chip that opens the QR in a dialog, so a
 * late player can scan it from any phone in the room.
 */
export function CodeChip({
    code,
    join,
}: {
    code: string;
    join: JoinLink | null;
}) {
    const { t } = useTranslation();

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className="h-11 gap-2 rounded-full px-4 tabular-nums"
                    aria-label={t('rooms.show.code_chip', {
                        code: spacedCode(code),
                    })}
                >
                    <QrCode aria-hidden />
                    {spacedCode(code)}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-sm" showCloseButton={false}>
                <DialogHeader>
                    <DialogTitle>{t('rooms.show.code')}</DialogTitle>
                    {join !== null && (
                        <DialogDescription>
                            {t('rooms.screen.join', {
                                address: typedAddress(join.entry_url),
                            })}
                        </DialogDescription>
                    )}
                </DialogHeader>
                <div className="flex flex-col items-center gap-4 pb-2">
                    <span className="text-5xl font-bold tracking-wider tabular-nums">
                        {spacedCode(code)}
                    </span>
                    {join !== null && <RoomQr url={join.url} size={224} />}
                </div>
                <DialogClose asChild>
                    <Button type="button" variant="secondary" className="h-11">
                        {t('common.close')}
                    </Button>
                </DialogClose>
            </DialogContent>
        </Dialog>
    );
}
