import type { ComponentType } from 'react';
import {
    RecallCellsAsk,
    RecallCellsSolution,
    RecallCellsStudy,
} from '@/components/rooms/mechanics/recall-cells';
import {
    RecallOrderAsk,
    RecallOrderSolution,
    RecallOrderStudy,
} from '@/components/rooms/mechanics/recall-order';
import type {
    RoomAsk,
    RoomMechanic,
    RoomMine,
    RoomStudy,
} from '@/rooms/contract';

/**
 * A mechanic's view (architecture §12 "Room games"): its study, its
 * question and its reveal. A mechanic is a builder on the server, an answer
 * shape for the judge and this; the room page and the screen draw a round
 * through it.
 */
export type MechanicView = {
    Study: ComponentType<{ study: RoomStudy }>;
    Ask: ComponentType<{
        ask: RoomAsk;
        picked: string[];
        disabled: boolean;
        onPick: (id: string) => void;
    }>;
    Solution: ComponentType<{
        ask: RoomAsk;
        solution: string[];
        mine: RoomMine | null;
    }>;
};

export const MECHANIC_VIEWS: Record<RoomMechanic, MechanicView> = {
    recall_order: {
        Study: RecallOrderStudy,
        Ask: RecallOrderAsk,
        Solution: RecallOrderSolution,
    },
    recall_cells: {
        Study: RecallCellsStudy,
        Ask: RecallCellsAsk,
        Solution: RecallCellsSolution,
    },
};
