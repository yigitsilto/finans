<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(): View
    {
        return view('dashboard', [
            'data' => $this->dashboard->data(),
            'hasAccounts' => Account::exists(),
        ]);
    }

    /**
     * Dashboard API'si (oturum ile korunur). Tutarlar finansal hassasiyet kaybı olmasın diye
     * string olarak döner (ör. "1234.50").
     */
    public function api(): JsonResponse
    {
        return response()->json($this->dashboard->data());
    }
}
