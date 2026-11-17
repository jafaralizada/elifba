import {
    ChoiceOptions,
    ChoiceReveal,
} from '@/components/rooms/mechanics/choice';
import { useTranslation } from '@/hooks/use-translation';
import type { RoomAsk, RoomMine } from '@/rooms/contract';

/*
 * «Artıq olanı tap» (docs/architecture.md §12 "Room games",
 * docs/frontend.md rule 15): four or five numbers or words as buttons of
 * one size — four two by two, five in one column, so none stands apart —
 * and a tap chooses the one that does not belong. The reveal marks the
 * odd one from `reveal.solution` and the viewer's own choice by the
 * server's verdict. The page never looks for what the others share.
 */

export function OddOneOutAsk({
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
            label={t('rooms.mechanics.odd_one_out.ask')}
            big={big}
        />
    );
}

export function OddOneOutSolution({
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
