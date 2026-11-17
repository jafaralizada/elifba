import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The action of the moment on a room page, stuck to the bottom of the
 * screen above the tab bar (docs/frontend.md rule 15, "Phone first"), so
 * a phone never scrolls to it: «Yeni oyun», «Otağa keç», «Qoşul». The
 * page's root is a column that fills the shell, and the bar sits at its
 * end (`mt-auto`), at the bottom of a short page too.
 */
export function RoomActionBar({
    children,
    className,
}: {
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'sticky bottom-[calc(4rem+env(safe-area-inset-bottom))] z-20 mt-auto border-t bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/85 lg:bottom-0',
                className,
            )}
        >
            <div className="mx-auto flex w-full max-w-xl flex-col gap-2 px-4 py-3 md:px-6">
                {children}
            </div>
        </div>
    );
}
