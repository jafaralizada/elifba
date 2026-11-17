import {
    ChoiceOptions,
    ChoiceSolution,
} from '@/components/rooms/mechanics/choice';
import { useTranslation } from '@/hooks/use-translation';
import type { RoomAsk, RoomMine } from '@/rooms/contract';

/*
 * «Artıq olanı tap» (docs/architecture.md §12 "Room games"): four or five
 * numbers or words, one of which does not belong; tap it. The reveal says
 * which one it was. Plain: the next session designs it.
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
    return (
        <ChoiceSolution ask={ask} solution={solution} mine={mine} big={big} />
    );
}
