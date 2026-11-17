import { RecallOrderAsk } from '@/components/rooms/mechanics/recall-order';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RoomAsk, RoomMine } from '@/rooms/contract';

/*
 * «Anaqram» (docs/architecture.md §12 "Room games"), plain: B6 part 2
 * designs it. The question is the word's letters as the server sent them —
 * cased there, shuffled there, never in the order of a word they spell —
 * tapped in order like a row to recall, with «Geri» on the page; the
 * reveal is the word, from `reveal.word`, and the viewer's own letters.
 * Nothing here knows the word before the reveal, and right or wrong is the
 * server's word (`mine.correct`): another word the letters spell is right
 * too.
 */

/** The letters to tap into a word, as a row is tapped. */
export const AnagramAsk = RecallOrderAsk;

/** The word, and the letters the viewer tapped, in their order. */
export function AnagramSolution({
    ask,
    mine,
    word,
    big = false,
}: {
    ask: RoomAsk;
    solution: string[];
    mine: RoomMine | null;
    word?: string | null;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const letters = new Map(ask.items.map((item) => [item.id, item.text]));

    return (
        <div
            className={cn(
                'flex flex-col items-center',
                big ? 'gap-[3vh]' : 'gap-3',
            )}
        >
            <div className="flex flex-col items-center gap-1">
                <span
                    className={cn(
                        'text-sm text-muted-foreground',
                        big && 'text-[2.4vh]',
                    )}
                >
                    {t('rooms.game.word')}
                </span>
                <span
                    className={cn(
                        'font-semibold tracking-widest',
                        big ? 'text-[min(8vh,6vw)]' : 'text-3xl',
                    )}
                >
                    {word ?? ''}
                </span>
            </div>
            {mine !== null && (
                <div className="flex flex-col items-center gap-1">
                    <span className="text-sm text-muted-foreground">
                        {t('rooms.game.yours')}
                    </span>
                    <span className="text-xl font-semibold tracking-widest">
                        {mine.picked
                            .map((id) => letters.get(id) ?? '')
                            .join('')}
                    </span>
                </div>
            )}
        </div>
    );
}
