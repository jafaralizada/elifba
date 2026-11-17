<?php

namespace App\Domain\Rooms\Support\Mechanics;

use App\Domain\Content\Data\GridData;
use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomAskData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Data\RoomSolutionData;
use App\Domain\Rooms\Data\RoomStudyData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Rounds of `recall_cells`, «Xanaları yadda saxla» (docs/architecture.md
 * §12 "Room games", ADR 023): some cells of the level's grid light up for
 * its `study_ms`, then go dark; the question is the dark grid, and the
 * answer is the cells that were lit, in any order (`set`, `pick` = how
 * many were lit).
 *
 * Nothing a device gets before the reveal tells a lit cell from a dark
 * one but the study itself, which is served only while it is studied:
 *
 * - The question is built first, before anything is drawn about the lit
 *   cells: every cell of the grid in reading order (row by row, left to
 *   right), each with an id of its own. So neither its ids nor their
 *   order nor anything else in it depends on which cells were lit; only
 *   `pick` says how many, which the study showed anyway.
 * - The study is the lit cells only, in reading order, with ids of their
 *   own drawn after the question's: no id of the question is ever shown
 *   with what was lit.
 * - The solution is the question's ids of the lit cells.
 *
 * The level's `lit` is drawn per round, from 1 to fewer than the grid's
 * cells, so a dark cell is always left (`ContentValidator`).
 */
final readonly class RecallCellsBuilder implements RoomRoundBuilder
{
    public function build(RoomLevelData $level, int $count, Randomizer $random): array
    {
        $grid = $level->grid;
        $lit = $level->lit;

        if ($grid === null || $lit === null || $grid->cols < 1 || $grid->rows < 1 || $lit->min < 1 || $lit->min > $lit->max || $lit->max >= $grid->cells() || $level->studyMs < 1) {
            throw new InvalidArgumentException('A recall_cells level needs a grid, fewer lit cells than it has and time to study.');
        }

        $rounds = [];

        for ($index = 0; $index < $count; $index++) {
            $rounds[] = $this->round($level, $grid, $lit, $random);
        }

        return $rounds;
    }

    private function round(RoomLevelData $level, GridData $grid, RangeData $lit, Randomizer $random): BuiltRoomRoundData
    {
        $ids = new RoomItemIds($random);
        $cells = [];

        for ($row = 0; $row < $grid->rows; $row++) {
            for ($col = 0; $col < $grid->cols; $col++) {
                $cells[] = [$col, $row];
            }
        }

        // The question: the whole dark grid, drawn before the lit cells are.
        $asked = array_map(static fn (array $cell): RoomItemData => new RoomItemData($ids->next(), RoomItemKind::Cell, cell: $cell), $cells);

        // Then the lit cells, in reading order (`pickArrayKeys` keeps it).
        $shown = $random->getInt($lit->min, $lit->max);

        if ($cells === [] || $shown < 1) {
            throw new InvalidArgumentException('A recall_cells round lights at least one cell of a grid.');
        }

        /** @var list<int> $positions */
        $positions = $random->pickArrayKeys($cells, $shown);
        $study = array_map(static fn (int $position): RoomItemData => new RoomItemData($ids->next(), RoomItemKind::Cell, cell: $cells[$position]), $positions);

        return new BuiltRoomRoundData(
            study: new RoomStudyData(RoomLayout::Grid, $grid->toArray(), $study),
            ask: new RoomAskData(RoomAnswerShape::Set, $shown, RoomLayout::Grid, $grid->toArray(), $asked),
            solution: new RoomSolutionData(RoomAnswerShape::Set, array_map(static fn (int $position): string => $asked[$position]->id, $positions)),
            studyMs: $level->studyMs,
            answerMs: $level->answerMs,
        );
    }
}
