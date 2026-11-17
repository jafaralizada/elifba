import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { RoomAsk, RoomItem, RoomMine, RoomStudy } from '@/rooms/contract';

/*
 * «Ardıcıllığı yadda saxla» on the room page (docs/architecture.md §12
 * "Room games", B3): the row to study, the question to tap in order, and
 * the order that was right. Every item is drawn as the server sent it —
 * its text is content, cased on the server — and nothing here knows the
 * order before the reveal: the question comes shuffled, and only
 * `reveal.solution` says which way it goes. Plain on purpose; B4 designs it.
 */

function tile(item: RoomItem): string {
    return item.text ?? '';
}

/** The row in the order shown; drawn only from a state in its study phase. */
export function RecallOrderStudy({ study }: { study: RoomStudy }) {
    return (
        <ol className="flex flex-wrap justify-center gap-2">
            {study.items.map((item) => (
                <li
                    key={item.id}
                    className="flex size-14 items-center justify-center rounded-xl border bg-card text-2xl font-semibold"
                >
                    {tile(item)}
                </li>
            ))}
        </ol>
    );
}

/**
 * The question's items in the order the server sent them; each tap adds an
 * item to the answer, which is sent once it holds `ask.pick` items.
 */
export function RecallOrderAsk({
    ask,
    picked,
    disabled,
    onPick,
}: {
    ask: RoomAsk;
    picked: string[];
    disabled: boolean;
    onPick: (id: string) => void;
}) {
    return (
        <div className="flex flex-wrap justify-center gap-2">
            {ask.items.map((item) => {
                const position = picked.indexOf(item.id);

                return (
                    <Button
                        key={item.id}
                        type="button"
                        variant={position === -1 ? 'outline' : 'secondary'}
                        className="relative size-14 text-2xl font-semibold"
                        disabled={disabled || position !== -1}
                        onClick={() => onPick(item.id)}
                    >
                        {tile(item)}
                        {position !== -1 && (
                            <span className="absolute top-0.5 right-1 text-xs text-muted-foreground tabular-nums">
                                {position + 1}
                            </span>
                        )}
                    </Button>
                );
            })}
        </div>
    );
}

/** A list of ids as the question's items say them, in that order. */
function Row({ ask, ids }: { ask: RoomAsk; ids: string[] }) {
    const items = new Map(ask.items.map((item) => [item.id, item]));

    return (
        <ol className="flex flex-wrap justify-center gap-2">
            {ids.map((id, index) => {
                const item = items.get(id);

                return (
                    <li
                        key={`${id}-${index}`}
                        className="flex size-12 items-center justify-center rounded-lg border bg-card text-xl font-semibold"
                    >
                        {item === undefined ? '' : tile(item)}
                    </li>
                );
            })}
        </ol>
    );
}

/** The right order, from the reveal, and the viewer's own below it. */
export function RecallOrderSolution({
    ask,
    solution,
    mine,
}: {
    ask: RoomAsk;
    solution: string[];
    mine: RoomMine | null;
}) {
    const { t } = useTranslation();

    return (
        <div className="flex flex-col items-center gap-2">
            <span className="text-sm text-muted-foreground">
                {t('rooms.game.solution')}
            </span>
            <Row ask={ask} ids={solution} />
            {mine !== null && (
                <>
                    <span className="text-sm text-muted-foreground">
                        {t('rooms.game.yours')}
                    </span>
                    <Row ask={ask} ids={mine.picked} />
                </>
            )}
        </div>
    );
}
