<?php

namespace App\Reports;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * ReportTable → PDF (dompdf). Türkçe karakterler için DejaVu Sans gömülür.
 * Çok büyük raporlarda PDF ilk MAX_ROWS satırla sınırlanır ve bu durum belgede yazılır;
 * tam veri için Excel önerilir.
 *
 * Ölçüm (11 sütun, DejaVu Sans): 250 satır ≈ 104 MB / 0,9 sn, 500 satır ≈ 194 MB / 2,2 sn,
 * 1000 satır ≈ 446 MB / 6 sn. Bu yüzden sınır 500 satır ve PDF üretimi sırasında bellek
 * sınırı geçici olarak MEMORY_LIMIT'e yükseltilir.
 */
class PdfRenderer
{
    public const MAX_ROWS = 500;

    public const MEMORY_LIMIT = '384M';

    public function render(ReportTable $report, string $generatedBy): string
    {
        $truncated = false;
        $remaining = self::MAX_ROWS;
        foreach ($report->sections as &$section) {
            if (count($section['rows']) > $remaining) {
                $section['rows'] = array_slice($section['rows'], 0, $remaining);
                $truncated = true;
            }
            $remaining -= count($section['rows']);
        }
        unset($section);

        // Bellek sınırı yalnızca PDF üretimi süresince (gerekirse) yükseltilir.
        $previousLimit = ini_get('memory_limit');
        if ($previousLimit !== '-1' && self::bytes($previousLimit) < self::bytes(self::MEMORY_LIMIT)) {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }

        try {
            return $this->output($report, $generatedBy, $truncated);
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }

    private static function bytes(string $limit): int
    {
        $value = (int) $limit;

        return match (strtoupper(substr(trim($limit), -1))) {
            'G' => $value * 1024 ** 3,
            'M' => $value * 1024 ** 2,
            'K' => $value * 1024,
            default => $value,
        };
    }

    private function output(ReportTable $report, string $generatedBy, bool $truncated): string
    {
        $pdf = Pdf::loadView('exports.pdf', [
            'report' => $report,
            'generatedBy' => $generatedBy,
            'truncated' => $truncated,
            'maxRows' => self::MAX_ROWS,
        ])->setPaper('a4', $report->maxColumns() > 6 ? 'landscape' : 'portrait')
            ->setOption([
                'defaultFont' => 'DejaVu Sans',
                'isFontSubsettingEnabled' => true, // yalnızca kullanılan karakterler gömülür (dosya boyutu)
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
            ]);

        return $pdf->output();
    }
}
