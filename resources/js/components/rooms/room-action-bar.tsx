import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/**
 * The action of the moment on a room page, stuck to the bottom of the
 * screen (docs/frontend.md rule 15, "Phone first"), so a phone never
 * scrolls to it: «Yeni oyun», «Başla», «Qoşul». The page's root is a column
 * at least one screen high, and the bar sits at its end (`mt-auto`), at the
 * bottom of a short page too.
 *
 * In the area's shell («Oyunlar», the entrance) it stands above the tab bar
 * below `lg`. In the game frame (`framed`, `rooms/show`, ADR 032) there is
 * no tab bar: it stands at the bottom of the viewport on every width and
 * pads its own safe area.
 */
export function RoomActionBar({
    children,
    framed = false,
    className,
}: {
    children: ReactNode;
    framed?: boolean;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'sticky z-20 mt-auto border-t bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/85',
                framed
                    ? 'bottom-0'
                    : 'bottom-[calc(4rem+env(safe-area-inset-bottom))] lg:bottom-0',
                className,
            )}
        >
            <div
                className={cn(
                    'mx-auto flex w-full max-w-xl flex-col gap-2',
                    framed
                        ? 'pt-3 pr-[max(1rem,env(safe-area-inset-right))] pb-[max(env(safe-area-inset-bottom),0.75rem)] pl-[max(1rem,env(safe-area-inset-left))] md:pr-[max(1.5rem,env(safe-area-inset-right))] md:pl-[max(1.5rem,env(safe-area-inset-left))]'
                        : 'px-4 py-3 md:px-6',
                )}
            >
                {children}
            </div>
        </div>
    );
}
