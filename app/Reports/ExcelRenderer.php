<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * ReportTable → .xlsx. Tutarlar sayısal hücre + TL biçimi ('#,##0.00 ₺') olarak yazılır;
 * Excel ayırıcıları kullanıcının bölge ayarına göre gösterir (TR: 1.234,56 ₺).
 * Not: xlsx sayıları IEEE double saklar; 2 basamaklı tutarlar 15 anlamlı basamağa kadar birebir gösterilir.
 */
class ExcelRenderer
{
    public const MONEY_FORMAT = '#,##0.00 "₺";[Red]-#,##0.00 "₺"';

    public const DATE_FORMAT = 'dd.mm.yyyy';

    public const PERCENT_FORMAT = '0.00"%"';

    private const HEADER_FILL = 'FF12355B';

    private const TOTAL_FILL = 'FFEEF2F7';

    public function render(ReportTable $report, string $generatedBy): string
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCreator('Zafir Sigorta Finans')->setTitle($report->title);
        $book->getDefaultStyle()->getFont()->setName('Calibri')->setSize(10);

        $sheet = $book->getActiveSheet();
        $sheet->setTitle(mb_substr($report->title, 0, 31));

        $row = 1;
        $sheet->setCellValue("A{$row}", 'Zafir Sigorta Aracılık Hizmetleri');
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
        $row++;
        $sheet->setCellValue("A{$row}", $report->title);
        $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
        $row++;
        $sheet->setCellValue("A{$row}", 'Dönem: '.$report->period);
        $row++;
        $sheet->setCellValue("A{$row}", 'Oluşturulma: '.now()->format('d.m.Y H:i').' · '.$generatedBy);
        $sheet->getStyle("A{$row}")->getFont()->getColor()->setARGB('FF6B7685');
        $row++;

        foreach ($report->filters as $label => $value) {
            $sheet->setCellValue("A{$row}", $label);
            $sheet->setCellValueExplicit("B{$row}", $value, DataType::TYPE_STRING);
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true);
            $row++;
        }
        $row++;

        if ($report->summary) {
            foreach ($report->summary as $item) {
                $sheet->setCellValue("A{$row}", $item['label']);
                $this->write($sheet, "B{$row}", $item['value'], $item['type']);
                $sheet->getStyle("A{$row}:B{$row}")->getFont()->setBold(true);
                $row++;
            }
            $row++;
        }

        foreach ($report->sections as $section) {
            if ($section['title']) {
                $sheet->setCellValue("A{$row}", $section['title']);
                $sheet->getStyle("A{$row}")->getFont()->setBold(true)->setSize(11);
                $row++;
            }

            $colCount = count($section['columns']);
            $end = Coordinate::stringFromColumnIndex($colCount);
            foreach ($section['columns'] as $i => $col) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1).$row, $col['label']);
            }
            $header = $sheet->getStyle("A{$row}:{$end}{$row}");
            $header->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
            $header->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_FILL);
            $headerRow = $row;
            $row++;

            // Veri satırları: değerler hücreye, biçim ise sütun aralığına TEK seferde uygulanır
            // (hücre başına stil, büyük raporlarda bellek tüketir).
            $firstDataRow = $row;
            foreach ($section['rows'] as $data) {
                foreach ($section['columns'] as $i => $col) {
                    $this->write($sheet, Coordinate::stringFromColumnIndex($i + 1).$row, $data[$i] ?? null, $col['type'], false);
                }
                $row++;
            }
            if ($row > $firstDataRow) {
                foreach ($section['columns'] as $i => $col) {
                    $format = self::formatFor($col['type']);
                    if ($format) {
                        $letter = Coordinate::stringFromColumnIndex($i + 1);
                        $sheet->getStyle("{$letter}{$firstDataRow}:{$letter}".($row - 1))->getNumberFormat()->setFormatCode($format);
                    }
                }
            }

            if ($section['totals'] !== null) {
                foreach ($section['columns'] as $i => $col) {
                    $value = $section['totals'][$i] ?? null;
                    $this->write($sheet, Coordinate::stringFromColumnIndex($i + 1).$row, $value, $i === 0 ? 'text' : $col['type']);
                }
                $total = $sheet->getStyle("A{$row}:{$end}{$row}");
                $total->getFont()->setBold(true);
                $total->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::TOTAL_FILL);
                $total->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
                $row++;
            }

            if ($row > $firstDataRow) {
                $sheet->setAutoFilter("A{$headerRow}:{$end}".($row - 1 - ($section['totals'] !== null ? 1 : 0)));
            }
            $row++;
        }

        foreach ($report->notes as $note) {
            $sheet->setCellValue("A{$row}", $note);
            $sheet->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setARGB('FF6B7685');
            $row++;
        }

        // Okunabilir sütun genişlikleri
        for ($i = 1; $i <= $report->maxColumns(); $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }
        $sheet->calculateColumnWidths();
        foreach ($sheet->getColumnDimensions() as $dim) {
            $width = $dim->getWidth();
            $dim->setAutoSize(false)->setWidth(min(max($width, 12), 60));
        }
        $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    private static function formatFor(string $type): ?string
    {
        return match ($type) {
            'money' => self::MONEY_FORMAT,
            'percent' => self::PERCENT_FORMAT,
            'date' => self::DATE_FORMAT,
            default => null,
        };
    }

    private function write(Worksheet $sheet, string $cell, mixed $value, string $type, bool $applyFormat = true): void
    {
        if ($value === null || $value === '') {
            return;
        }

        match ($type) {
            'money', 'percent', 'int' => $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_NUMERIC),
            'date' => $sheet->setCellValue($cell, ExcelDate::PHPToExcel(CarbonImmutable::parse($value)->startOfDay())),
            default => $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING),
        };

        if ($applyFormat && ($format = self::formatFor($type))) {
            $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($format);
        }
    }
}
