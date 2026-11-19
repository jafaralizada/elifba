import { Head, Link } from '@inertiajs/react';
import { ChevronLeft, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index, past } from '@/routes/rooms';

/**
 * A room's page without the app's shell (docs/frontend.md rule 15 "The game
 * frame", ADR 032): one top bar and the page under it, at least one screen
 * high, so a `RoomActionBar framed` at its end stands at the bottom of the
 * viewport. No logo, account menu, sidebar, tab bar or install banner; the
 * app's tokens in both themes, and no motion of its own.
 *
 * The top bar, sticky, in the children's remote's order and never importing
 * it: ‹ to «Oyunlar» — to «Keçmiş oyunlar» for a closed room (`back`) — on
 * every state, first; the page's heading (`heading`: its `h1`, one line,
 * truncated); then the moment's `tools` — in a round «Oyun səsləri», the live
 * voice's button and the code, outside one the live voice's button and the
 * host's «Otağın idarəsi». ‹ is not leaving: the player stays in the game,
 * and «Oyunlar» has its card with «Oyuna qayıt».
 */
export function RoomFrame({
    back = 'rooms',
    heading,
    tools = null,
    children,
}: {
    back?: 'rooms' | 'past';
    heading: ReactNode;
    tools?: ReactNode;
    children: ReactNode;
}) {
    const { t } = useTranslation();
    const toPast = back === 'past';

    return (
        <div className="flex min-h-svh flex-col bg-background text-foreground">
            <header className="sticky top-0 z-30 border-b pt-[env(safe-area-inset-top)]">
                {/* The bar's ground is a layer of its own: a backdrop filter on the header would make it the containing block of what is `fixed` inside it — the live voice's note. */}
                <div
                    aria-hidden
                    className="absolute inset-0 -z-10 bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/85"
                />
                <div className="mx-auto flex h-14 w-full max-w-xl items-center gap-2 pr-[max(0.5rem,env(safe-area-inset-right))] pl-[max(0.5rem,env(safe-area-inset-left))]">
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        className="size-11 shrink-0"
                    >
                        <Link
                            href={toPast ? past() : index()}
                            aria-label={t(
                                toPast ? 'nav.rooms_past' : 'nav.rooms',
                            )}
                        >
                            <ChevronLeft className="size-6" />
                        </Link>
                    </Button>
                    <div className="flex min-w-0 flex-1 flex-col justify-center">
                        {heading}
                    </div>
                    {tools}
                </div>
            </header>
            {children}
        </div>
    );
}

/** The top bar's heading outside a round: the page's title as its `h1` and `<title>`, with a badge beside it. */
export function FrameTitle({
    title,
    badge = null,
}: {
    title: string;
    badge?: ReactNode;
}) {
    return (
        <div className="flex min-w-0 items-center gap-2">
            <Head title={title} />
            <h1 className="min-w-0 truncate text-base leading-tight font-semibold">
                {title}
            </h1>
            {badge}
        </div>
    );
}

/** «Kilidli» beside the host's title while the room takes no one new. */
export function LockedBadge() {
    const { t } = useTranslation();

    return (
        <Badge variant="outline" className="h-6 shrink-0 gap-1">
            <Lock aria-hidden />
            {t('rooms.show.locked_badge')}
        </Badge>
    );
}
