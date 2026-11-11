<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Loan;
use App\Models\LoanInstallment;
use App\Services\LoanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LoanController extends Controller
{
    public function __construct(private readonly LoanService $loans) {}

    public function index(): View
    {
        $loans = Loan::with('account')->orderBy('start_date', 'desc')->get();

        return view('loans.index', [
            'loans' => $loans,
            'summaries' => $loans->mapWithKeys(fn ($l) => [$l->id => $this->loans->summary($l)]),
        ]);
    }

    public function create(): View
    {
        return view('loans.form', ['banks' => $this->bankAccounts()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', 'unique:loans,name'],
            'bank_name' => ['required', 'string', 'max:120'],
            'account_id' => ['required', 'integer'],
            'start_date' => ['required', 'date', 'before_or_equal:today'],
            'principal' => ['required', 'string', 'max:30'],
            'annual_interest_rate' => ['required', 'string', 'max:12', 'regex:/^\d{1,3}([.,]\d{1,4})?$/'],
            'term_months' => ['required', 'integer', 'between:1,360'],
            'first_due_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'record_disbursement' => ['nullable', 'boolean'],
        ], ['annual_interest_rate.regex' => 'Faiz oranı en fazla 4 ondalıklı bir yüzde olmalıdır (ör. 42,5).'], [
            'name' => 'Kredi adı', 'bank_name' => 'Banka', 'account_id' => 'Hesap', 'start_date' => 'Kullandırım tarihi',
            'principal' => 'Kredi tutarı', 'annual_interest_rate' => 'Yıllık faiz', 'term_months' => 'Vade (ay)',
            'first_due_date' => 'İlk taksit tarihi', 'notes' => 'Notlar',
        ]);
        $data['record_disbursement'] = $request->boolean('record_disbursement');

        $loan = $this->loans->create($data, $request->user());

        return redirect()->route('loans.show', $loan)->with('status', 'Kredi ve taksit planı oluşturuldu.');
    }

    public function show(Loan $loan): View
    {
        $loan->load('account');
        $installments = $loan->installments()->with('payments.category')->get();

        return view('loans.show', [
            'loan' => $loan,
            'installments' => $installments,
            'summary' => $this->loans->summary($loan),
            'banks' => $this->bankAccounts(),
        ]);
    }

    public function pay(Request $request, LoanInstallment $installment): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'account_id' => ['required', 'integer'],
        ], [], ['date' => 'Ödeme tarihi', 'account_id' => 'Ödeme hesabı']);

        $this->loans->payInstallment($installment, $data['date'], (int) $data['account_id'], $request->user());

        return redirect()->route('loans.show', $installment->loan_id)->with('status', "{$installment->number}. taksit ödendi (anapara ve faiz ayrı kaydedildi).");
    }

    public function reversePayment(Request $request, LoanInstallment $installment): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [], ['date' => 'İptal tarihi', 'reason' => 'İptal nedeni']);

        $this->loans->reversePayment($installment, $data['date'], $data['reason'], $request->user());

        return redirect()->route('loans.show', $installment->loan_id)->with('status', "{$installment->number}. taksit ödemesi iptal edildi.");
    }

    public function updateInstallment(Request $request, LoanInstallment $installment): RedirectResponse
    {
        $data = $request->validate([
            'due_date' => ['required', 'date'],
            'principal' => ['required', 'string', 'max:30'],
            'interest' => ['required', 'string', 'max:30'],
        ], [], ['due_date' => 'Vade', 'principal' => 'Anapara', 'interest' => 'Faiz']);

        $this->loans->updateInstallment($installment, $data, $request->user());

        return redirect()->route('loans.show', $installment->loan_id)->with('status', "{$installment->number}. taksit güncellendi.");
    }

    /** @return array<int, string> */
    private function bankAccounts(): array
    {
        return Account::where('type', AccountType::Bank)->active()->orderBy('name')->get()
            ->mapWithKeys(fn ($a) => [$a->id => $a->displayName()])->all();
    }
}
