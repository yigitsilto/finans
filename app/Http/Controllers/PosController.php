<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\BalanceService;
use App\Services\TransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PosController extends Controller
{
    public function __construct(private readonly TransactionService $service) {}

    public function index(BalanceService $balances): View
    {
        $pending = $this->service->pendingPosInflows()
            ->with(['toAccount.settlementAccount', 'category'])
            ->orderBy('pos_settlement_date')->orderBy('id')
            ->get();

        return view('pos.index', [
            'accounts' => Account::where('type', AccountType::Pos)->with('settlementAccount')->orderBy('name')->get(),
            'balances' => $balances->balances(),
            'pending' => $pending,
            'dueCount' => $pending->filter(fn ($t) => $t->pos_settlement_date && $t->pos_settlement_date->lte(today()))->count(),
        ]);
    }

    public function settle(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate(['date' => ['nullable', 'date']], [], ['date' => 'Aktarım tarihi']);

        $transfer = $this->service->settlePos($transaction, $data['date'] ?? null, $request->user());

        return redirect()->route('pos.index')->with('status', "POS girişi #{$transaction->id} bankaya aktarıldı (transfer #{$transfer->id}).");
    }

    public function settleDue(Request $request): RedirectResponse
    {
        $transfers = $this->service->settleDuePos($request->user());

        return redirect()->route('pos.index')->with('status', $transfers->isEmpty()
            ? 'Valör tarihi gelmiş bekleyen POS girişi yok.'
            : "{$transfers->count()} POS girişi bankaya aktarıldı.");
    }
}
