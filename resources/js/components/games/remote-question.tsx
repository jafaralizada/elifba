import type { Round } from '@/game/contract';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    round: Round;
};

/**
 * What a `count` or `first_letter` round asks, above the phone's buttons
 * (docs/architecture.md §2, ADR 012): the picture the board draws and, for
 * `count`, how many times it draws it, «× N», so the adult knows the answer
 * without walking to the board. Nothing shows for a round with no picture.
 */
export function RemoteQuestion({ round }: Props) {
    const { t } = useTranslation();
    const { image, count } = round.prompt;

    if (image === null || (round.mechanic === 'count' && count === null)) {
        return null;
    }

    return (
        <div
            className="flex items-center justify-center gap-3"
            role={count === null ? undefined : 'img'}
            aria-label={
                count === null ? undefined : t('games.remote.count', { count })
            }
        >
            <img
                src={image}
                alt={count === null ? t('games.remote.word_picture') : ''}
                className="size-20 rounded-2xl border bg-white object-contain p-2"
            />
            {count !== null && (
                <span
                    className="font-board text-5xl leading-none font-bold"
                    aria-hidden
                >
                    × {count}
                </span>
            )}
        </div>
    );
}
