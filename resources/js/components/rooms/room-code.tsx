import { QrCode } from 'lucide-react';
import { QRCodeSVG } from 'qrcode.react';
import type { ReactNode } from 'react';
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

/**
 * The address of the code field as a person types it: no scheme, and no
 * port a browser adds by itself — an `APP_URL` of `https://elifba.io:443`
 * reads «elifba.io/oyun».
 */
export function typedAddress(url: string): string {
    try {
        const address = new URL(url);

        return `${address.host}${address.pathname === '/' ? '' : address.pathname}`;
    } catch {
        return url.replace(/^https?:\/\//, '');
    }
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
 * The start screen's invitation (docs/frontend.md rule 15 "Games first",
 * ADR 026 decision 16): the QR of `join.url` (dark on white in both
 * themes), «Oyun kodu», the code in two groups of three and how to join
 * with either. `qr` is its side: 160px, 120px under the room's standing.
 */
export function InviteCard({
    code,
    join,
    qr = 160,
}: {
    code: string;
    join: JoinLink | null;
    qr?: number;
}) {
    const { t } = useTranslation();

    return (
        <section
            aria-labelledby="room-code"
            className="flex flex-col items-center gap-1 text-center"
        >
            {join !== null && (
                <RoomQr url={join.url} size={qr} className="mb-0.5 p-1.5" />
            )}
            <span id="room-code" className="text-xs text-muted-foreground">
                {t('rooms.show.code')}
            </span>
            <span className="text-[2.375rem] leading-none font-bold tracking-wider whitespace-nowrap tabular-nums">
                {spacedCode(code)}
            </span>
            {join !== null && (
                <span className="max-w-xs text-xs leading-snug text-balance text-muted-foreground">
                    {t('rooms.screen.join', {
                        address: typedAddress(join.entry_url),
                    })}
                </span>
            )}
        </section>
    );
}

/**
 * The code and its 224px QR in a dialog, so a late player can scan it from
 * any phone in the room: behind the round's code chip (`CodeChip`) and a
 * player's «Dostunu çağır».
 */
export function CodeDialog({
    code,
    join,
    trigger,
}: {
    code: string;
    join: JoinLink | null;
    trigger: ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <Dialog>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent
                className="max-w-sm motion-reduce:animate-none"
                showCloseButton={false}
            >
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

/** The code in a round's header: a chip that opens it with its QR (`CodeDialog`). */
export function CodeChip({
    code,
    join,
}: {
    code: string;
    join: JoinLink | null;
}) {
    const { t } = useTranslation();

    return (
        <CodeDialog
            code={code}
            join={join}
            trigger={
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
            }
        />
    );
}
