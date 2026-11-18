import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The action of the moment on a room page, stuck to the bottom of the
 * screen above the tab bar (docs/frontend.md rule 15, "Phone first"), so
 * a phone never scrolls to it: «Yeni oyun», «Otağa keç», «Qoşul». The
 * page's root is a column that fills the shell, and the bar sits at its
 * end (`mt-auto`), at the bottom of a short page too. While the host has
 * the live voice on, the voice bar stands above the action, in the same
 * stuck block (`voice`, docs/frontend.md rule 15 "Live voice").
 */
export function RoomActionBar({
    children,
    className,
    voice,
}: {
    children: ReactNode;
    className?: string;
    /** The voice bar (`VoiceBar`) above the action while the host has the voice on (C4, ADR 031 decision 34). */
    voice?: ReactNode;
}) {
    const action = (
        <div className="mx-auto flex w-full max-w-xl flex-col gap-2 px-4 py-3 md:px-6">
            {children}
        </div>
    );

    if (voice === undefined) {
        return (
            <div
                className={cn(
                    'sticky bottom-[calc(4rem+env(safe-area-inset-bottom))] z-20 mt-auto border-t bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/85 lg:bottom-0',
                    className,
                )}
            >
                {action}
            </div>
        );
    }

    return (
        <div
            className={cn(
                'sticky bottom-[calc(4rem+env(safe-area-inset-bottom))] z-20 mt-auto bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/85 lg:bottom-0',
                className,
            )}
        >
            {voice}
            <div className="border-t">{action}</div>
        </div>
    );
}
