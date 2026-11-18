import { useLayoutEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** Never smaller than this: past it the screen scrolls rather than shrink further. */
const LEAST = 0.5;

/**
 * The screen's round under the rivalry strip (docs/frontend.md rule 15
 * "Rivals face to face", ADR 027 "As built"): what a mechanic draws in
 * `vh` for the whole screen is drawn smaller — never larger — when it does
 * not fit the space the strip and the round's line leave, by CSS `zoom`.
 * The zoom is measured when the box is drawn and when the window changes
 * size, never because of anything a player did; it is a size, not a
 * movement. `cap` is a zoom it never goes above: the study's, so the
 * question's grid takes the place and the size the study's had.
 */
export function ScreenFit({
    children,
    className,
    cap = 1,
    onFit,
}: {
    children: ReactNode;
    className?: string;
    cap?: number;
    /** The zoom it took. */
    onFit?: (zoom: number) => void;
}) {
    const outer = useRef<HTMLDivElement>(null);
    const inner = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        const box = outer.current;
        const content = inner.current;

        if (box === null || content === null) {
            return;
        }

        const fit = () => {
            content.style.zoom = '1';

            const tall = box.clientHeight / Math.max(1, content.scrollHeight);
            const wide = box.clientWidth / Math.max(1, content.scrollWidth);
            const zoom = Math.max(LEAST, Math.min(1, cap, tall, wide));

            content.style.zoom = String(zoom);
            onFit?.(zoom);
        };

        fit();

        if (typeof ResizeObserver === 'undefined') {
            return;
        }

        let width = box.clientWidth;
        let height = box.clientHeight;
        const observer = new ResizeObserver(() => {
            // Only the box's own size: never the content it zooms.
            if (box.clientWidth !== width || box.clientHeight !== height) {
                width = box.clientWidth;
                height = box.clientHeight;
                fit();
            }
        });

        observer.observe(box);

        return () => observer.disconnect();
    }, [cap, onFit]);

    return (
        <div
            ref={outer}
            className={cn('flex min-h-0 min-w-0 flex-col', className)}
        >
            <div ref={inner} className="flex h-full min-h-0 flex-col">
                {children}
            </div>
        </div>
    );
}
