<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\AgingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AgingController extends Controller
{
    public function index(Request $request, AgingService $aging): View
    {
        $filters = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'customer_id' => ['nullable', 'integer'],
            'view' => ['nullable', Rule::in(['customer', 'detail'])],
            'bucket' => ['nullable', Rule::in(array_keys(AgingService::BUCKETS))],
        ], [], ['date' => 'Rapor tarihi']);

        $date = CarbonImmutable::parse($filters['date'] ?? today()->toDateString())->startOfDay();
        $report = $aging->report($date, isset($filters['customer_id']) ? (int) $filters['customer_id'] : null);

        $lines = $report['lines'];
        if ($filters['bucket'] ?? null) {
            $lines = $lines->where('bucket', $filters['bucket'])->values();
        }

        return view('aging.index', [
            'report' => $report,
            'lines' => $lines,
            'filters' => $filters + ['view' => 'customer'],
            'customers' => Customer::orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
