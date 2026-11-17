<?php

namespace App\Domain\Content\Data;

/**
 * A grid of a `recall_cells` level (docs/architecture.md §12 "Room
 * games"): `cols` across, `rows` down, each 2–6 (`ContentValidator`).
 */
final readonly class GridData
{
    public function __construct(
        public int $cols,
        public int $rows,
    ) {}

    /**
     * @param  array{cols: int, rows: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['cols'], $data['rows']);
    }

    public function cells(): int
    {
        return $this->cols * $this->rows;
    }

    /**
     * @return array{cols: int, rows: int}
     */
    public function toArray(): array
    {
        return ['cols' => $this->cols, 'rows' => $this->rows];
    }
}
