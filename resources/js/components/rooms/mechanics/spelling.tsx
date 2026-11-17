import {
    ChoiceOptions,
    ChoiceReveal,
} from '@/components/rooms/mechanics/choice';
import { useTranslation } from '@/hooks/use-translation';
import type { RoomAsk, RoomMine } from '@/rooms/contract';

/*
 * «Düzgün yazılış» (docs/architecture.md §12 "Room games",
 * docs/frontend.md rule 15): four spellings of one word as the options of
 * a `choice` (B5's buttons of one size, two by two), in the order the
 * server sent them — the right one's place is the server's seed — and a
 * tap chooses. The reveal marks the right spelling from `reveal.solution`
 * and the viewer's own choice by the server's verdict. Nothing here knows
 * the right one before the reveal. B6 part 2 designs it.
 */

export function SpellingAsk({
    ask,
    picked,
    disabled,
    onPick,
    big = false,
}: {
    ask: RoomAsk;
    picked: string[];
    disabled: boolean;
    onPick: (id: string) => void;
    big?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <ChoiceOptions
            ask={ask}
            picked={picked}
            disabled={disabled}
            onPick={onPick}
            label={t('rooms.mechanics.spelling.ask')}
            big={big}
        />
    );
}

export function SpellingSolution({
    ask,
    solution,
    mine,
    big = false,
}: {
    ask: RoomAsk;
    solution: string[];
    mine: RoomMine | null;
    big?: boolean;
}) {
    return <ChoiceReveal ask={ask} solution={solution} mine={mine} big={big} />;
}
