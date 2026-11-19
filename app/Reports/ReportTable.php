<?php

namespace App\Reports;

/**
 * Biçimden bağımsız rapor tanımı. Excel ve PDF aynı tanımdan üretilir,
 * böylece iki çıktı daima aynı veriyi içerir.
 *
 * Hücre türleri: text, money (string "1234.50"), date (Y-m-d), int, percent (string "12.50").
 */
class ReportTable
{
    /** @var array<string, string> filtre adı => değer */
    public array $filters = [];

    /** @var list<array{label: string, value: string, type: string}> */
    public array $summary = [];

    /** @var list<array{title: ?string, columns: list<array{label: string, type: string}>, rows: list<list<mixed>>, totals: ?list<mixed>}> */
    public array $sections = [];

    /** @var list<string> */
    public array $notes = [];

    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $period,
    ) {}

    public function filter(string $label, ?string $value): self
    {
        if ($value !== null && $value !== '') {
            $this->filters[$label] = $value;
        }

        return $this;
    }

    public function summary(string $label, string $value, string $type = 'money'): self
    {
        $this->summary[] = compact('label', 'value', 'type');

        return $this;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $columns  [etiket, tür]
     * @param  list<list<mixed>>  $rows
     * @param  list<mixed>|null  $totals
     */
    public function section(?string $title, array $columns, array $rows, ?array $totals = null): self
    {
        $this->sections[] = [
            'title' => $title,
            'columns' => array_map(fn ($c) => ['label' => $c[0], 'type' => $c[1]], $columns),
            'rows' => $rows,
            'totals' => $totals,
        ];

        return $this;
    }

    public function note(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    public function rowCount(): int
    {
        return array_sum(array_map(fn ($s) => count($s['rows']), $this->sections));
    }

    public function maxColumns(): int
    {
        return max([2, ...array_map(fn ($s) => count($s['columns']), $this->sections)]);
    }
}
