import { Lock, Star } from 'lucide-react';
import Heading from '@/components/heading';
import { useTranslation } from '@/hooks/use-translation';
import { childStickers } from '@/lib/child-stickers';
import { cn } from '@/lib/utils';
import type { Avatar } from '@/types';

type Props = {
    totalStars: number;
    avatars: Avatar[];
};

/**
 * A child's sticker album on their page (ADR 011): unlocked by the child's
 * own stars, three stars per sticker, never compared with a sibling.
 */
export function ChildStickers({ totalStars, avatars }: Props) {
    const { t } = useTranslation();
    const stickers = childStickers(totalStars, avatars);
    const unlockedCount = stickers.filter((s) => s.unlocked).length;

    return (
        <section
            className="space-y-4"
            aria-labelledby="child-stickers"
            data-test="child-stickers"
        >
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <div id="child-stickers">
                    <Heading
                        variant="small"
                        title={t('children.stickers.title')}
                        description={t('children.stickers.description')}
                    />
                </div>
                <p className="text-sm font-medium text-muted-foreground tabular-nums">
                    {t('children.stickers.progress', {
                        unlocked: unlockedCount,
                        total: stickers.length,
                    })}
                </p>
            </div>

            <ul className="grid grid-cols-3 gap-2.5 sm:grid-cols-4 md:grid-cols-6">
                {stickers.map((sticker) => (
                    <li
                        key={sticker.key}
                        className={cn(
                            'relative flex flex-col items-center justify-center gap-2 rounded-2xl border p-3 text-center transition-colors',
                            sticker.unlocked
                                ? 'border-amber-300/80 bg-amber-50/40 dark:border-amber-500/40 dark:bg-amber-950/20'
                                : 'bg-muted/40 text-muted-foreground',
                        )}
                        data-test={`sticker-${sticker.key}`}
                        data-unlocked={sticker.unlocked ? 'true' : 'false'}
                    >
                        <img
                            src={sticker.url}
                            alt=""
                            loading="lazy"
                            className={cn(
                                'size-14 object-contain transition-transform',
                                !sticker.unlocked && 'opacity-25 grayscale',
                            )}
                        />
                        {sticker.unlocked ? (
                            <span className="inline-flex items-center gap-1 text-xs font-semibold text-amber-700 dark:text-amber-300">
                                <Star
                                    className="size-3.5 fill-amber-400 text-amber-500"
                                    aria-hidden
                                />
                                {t(`children.stickers.names.${sticker.key}`)}
                            </span>
                        ) : (
                            <span className="inline-flex items-center gap-1 text-xs font-medium tabular-nums">
                                <Lock className="size-3" aria-hidden />
                                {sticker.starsRequired}
                                <Star
                                    className="size-3 fill-amber-400 text-amber-500"
                                    aria-hidden
                                />
                            </span>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
