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
 * A mechanic's view (architecture §12 "Room games", docs/frontend.md rule
 * 15): its study, its question and its reveal, each at a phone's size or,
 * with `big`, the screen's. A mechanic is a builder on the server, an
 * answer shape for the judge and this; the room page and the screen draw
 * a round through it. The question's taps are the page's (`tap()`); on
 * the screen it is drawn without them.
 */
export type MechanicView = {
    Study: ComponentType<{ study: RoomStudy; big?: boolean }>;
    Ask: ComponentType<{
        ask: RoomAsk;
        picked: string[];
        disabled: boolean;
        onPick: (id: string) => void;
        big?: boolean;
    }>;
    Solution: ComponentType<{
        ask: RoomAsk;
        solution: string[];
        mine: RoomMine | null;
        big?: boolean;
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
