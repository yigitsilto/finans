<?php

namespace App\Http\Controllers;

use App\Services\FinancialAnalysisService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AnalysisController extends Controller
{
    public function index(Request $request, FinancialAnalysisService $analysis): View
    {
        $data = $request->validate(['horizon' => ['nullable', 'integer', Rule::in([7, 15, 30, 60, 90])]]);

        if (isset($data['horizon'])) {
            config(['finance.liquidity_horizon_days' => (int) $data['horizon']]);
        }

        return view('analysis.index', ['a' => $analysis->analyze()]);
    }
}
