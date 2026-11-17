import { Flame, Star } from 'lucide-react';
import type { RoundStep } from '@/game/engine';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    index: number;
    total: number;
    steps: readonly RoundStep[];
    streak: number;
    accent: string;
};

/**
 * Where the game stands in the board's header: the round count, a visual
 * strip of stars and dots for the rounds, and a streak badge once three or
 * more rounds in a row are solved on the first try.
 */
export function BoardProgress({ index, total, steps, streak, accent }: Props) {
    const { t } = useTranslation();

    return (
        <div className="flex items-center gap-3 max-lg:mr-auto max-lg:gap-2">
            {streak >= 3 && (
                <span
                    className="inline-flex items-center gap-1 rounded-full border-2 border-amber-400 bg-amber-50 px-2.5 py-0.5 text-sm font-bold text-amber-900 shadow-xs max-lg:px-2 max-lg:text-xs"
                    data-test="board-streak"
                >
                    <Flame
                        className="size-4 shrink-0 fill-amber-500 text-amber-600"
                        aria-hidden
                    />
                    {t('games.board.streak', { count: streak })}
                </span>
            )}

            {steps.length > 0 && steps.length <= 15 && (
                <span
                    aria-hidden
                    className="hidden items-center gap-1.5 md:flex"
                    data-test="board-steps"
                >
                    {steps.map((step, at) =>
                        step === 'flawless' ? (
                            <Star
                                key={at}
                                className="size-4 fill-amber-400 text-amber-500"
                            />
                        ) : step === 'solved' ? (
                            <span
                                key={at}
                                className="size-3 rounded-full"
                                style={{ backgroundColor: accent }}
                            />
                        ) : step === 'skipped' ? (
                            <span
                                key={at}
                                className="h-1.5 w-3 rounded-full bg-neutral-300"
                            />
                        ) : step === 'current' ? (
                            <span
                                key={at}
                                className="size-3.5 rounded-full border-[3px] bg-white"
                                style={{ borderColor: accent }}
                            />
                        ) : (
                            <span
                                key={at}
                                className="size-2.5 rounded-full bg-neutral-300/70"
                            />
                        ),
                    )}
                </span>
            )}

            <p className="text-lg font-semibold tabular-nums max-lg:text-sm max-lg:whitespace-nowrap">
                {t('games.board.round', {
                    index: index + 1,
                    total,
                })}
            </p>
        </div>
    );
}
