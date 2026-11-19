<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Reports\ExcelRenderer;
use App\Reports\PdfRenderer;
use App\Reports\ReportBuilder;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    public const FORMATS = ['xlsx' => 'Excel', 'pdf' => 'PDF'];

    public const MEMORY_LIMIT = '512M';

    public function index(): View
    {
        return view('exports.index', [
            'reports' => collect(ReportBuilder::REPORTS)->filter(fn ($_, $key) => $key !== 'audit' || Gate::allows('view-audit')),
            'accounts' => Account::orderBy('name')->pluck('name', 'id'),
            'customers' => Customer::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    /**
     * Rapor indirme. Finansal raporlar tüm aktif rollere (Viewer dahil) açıktır;
     * denetim kayıtları yalnızca Admin. Her indirme denetim kaydına yazılır.
     */
    public function download(Request $request, string $report, string $format, ReportBuilder $builder, AuditService $audit): Response
    {
        abort_unless(array_key_exists($report, ReportBuilder::REPORTS) && array_key_exists($format, self::FORMATS), 404);
        Gate::authorize($report === 'audit' ? 'view-audit' : 'view-finance');

        // Büyük raporlar için bellek sınırı yalnızca bu istekte yükseltilir.
        if (ini_get('memory_limit') !== '-1') {
            ini_set('memory_limit', self::MEMORY_LIMIT);
        }

        $table = $builder->build($report, $request);
        $user = $request->user();
        $generatedBy = "{$user->name} ({$user->username})";
        $filename = 'zafir-'.$report.'-'.now()->format('Ymd-His').'.'.$format;

        $audit->log(AuditService::EXPORTED, new: [
            'report' => $report,
            'format' => $format,
            'rows' => $table->rowCount(),
            'filters' => array_filter($request->except(['_token']), fn ($v) => $v !== null && $v !== ''),
        ], httpStatus: 200);

        if ($format === 'pdf') {
            return response(app(PdfRenderer::class)->render($table, $generatedBy), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-store, private',
            ]);
        }

        $path = app(ExcelRenderer::class)->render($table, $generatedBy);

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ])->deleteFileAfterSend();
    }
}
