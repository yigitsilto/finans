<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringExpense;
use App\Services\AuditService;
use App\Services\ExpenseService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function __construct(private readonly ExpenseService $expenses) {}

    public function index(Request $request): View
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $period = isset($data['month']) ? CarbonImmutable::createFromFormat('!Y-m', $data['month']) : CarbonImmutable::today()->startOfMonth();

        return view('expenses.index', [
            'period' => $period,
            'rows' => $this->expenses->monthStatus($period),
            'analysis' => $this->expenses->analysis($period->startOfMonth(), $period->endOfMonth()),
            'accounts' => $this->payableAccounts(),
        ]);
    }

    public function create(): View
    {
        return view('expenses.form', $this->formData(new RecurringExpense(['day_of_month' => 1, 'start_date' => today()->startOfMonth(), 'is_active' => true])));
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data, $request, $audit) {
            $expense = new RecurringExpense($data);
            $expense->created_by = $request->user()->id;
            $expense->is_active = true;
            $expense->save();
            $audit->log(AuditService::CREATED, $expense, new: $expense->only(['name', 'category_id', 'amount', 'day_of_month', 'start_date', 'end_date', 'account_id']));
        });

        return redirect()->route('expenses.index')->with('status', 'Tekrarlayan gider tanımlandı.');
    }

    public function edit(RecurringExpense $expense): View
    {
        return view('expenses.form', $this->formData($expense));
    }

    public function update(Request $request, RecurringExpense $expense, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($expense, $data, $request, $audit) {
            $original = $expense->getOriginal();
            $expense->fill($data);
            $expense->is_active = $request->boolean('is_active');
            $expense->save();
            [$old, $new] = AuditService::diff($original, $expense);
            if ($new !== []) {
                $audit->log(AuditService::UPDATED, $expense, $old, $new);
            }
        });

        return redirect()->route('expenses.index')->with('status', 'Gider tanımı güncellendi. Geçmiş ödemeler değişmez.');
    }

    public function pay(Request $request, RecurringExpense $expense): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:30'],
            'account_id' => ['required', 'integer'],
            'reference_no' => ['nullable', 'string', 'max:100'],
        ], [], ['date' => 'Ödeme tarihi', 'amount' => 'Tutar', 'account_id' => 'Ödeme hesabı']);

        $period = CarbonImmutable::createFromFormat('!Y-m', $data['month']);
        $this->expenses->pay($expense, $period, $data, $request->user());

        return redirect()->route('expenses.index', ['month' => $data['month']])->with('status', "{$expense->name} ödemesi kaydedildi.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category_id' => ['required', 'integer'],
            'amount' => ['required', 'string', 'max:30'],
            'day_of_month' => ['required', 'integer', 'between:1,31'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'account_id' => ['nullable', 'integer'],
            'counterparty' => ['nullable', 'string', 'max:150'],
        ], [], [
            'name' => 'Gider adı', 'category_id' => 'Kategori', 'amount' => 'Aylık tutar', 'day_of_month' => 'Ödeme günü',
            'start_date' => 'Başlangıç', 'end_date' => 'Bitiş', 'account_id' => 'Varsayılan hesap', 'counterparty' => 'Alıcı',
        ]);

        // Yalnızca sabit/değişken gider alt kategorileri tekrarlayan gider olabilir.
        $category = Category::selectable('outflow')->whereNotNull('cost_type')->find($data['category_id']);
        if (! $category) {
            throw ValidationException::withMessages(['category_id' => 'Sabit veya değişken gider kategorisi seçilmelidir.']);
        }

        try {
            $data['amount'] = Money::parse($data['amount']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }
        if (! BigDecimal::of($data['amount'])->isPositive()) {
            throw ValidationException::withMessages(['amount' => 'Tutar sıfırdan büyük olmalıdır.']);
        }

        if (! empty($data['account_id']) && ! $this->payableAccounts()->has($data['account_id'])) {
            throw ValidationException::withMessages(['account_id' => 'Geçerli bir kasa veya banka hesabı seçilmelidir.']);
        }

        return $data;
    }

    private function formData(RecurringExpense $expense): array
    {
        $options = Category::selectable('outflow')->whereNotNull('cost_type')->with('parent')->orderBy('sort')->get()
            ->groupBy(fn ($c) => $c->parent?->name ?? 'Genel')->map(fn ($g) => $g->pluck('name', 'id')->all());

        return ['expense' => $expense, 'categories' => $options, 'accounts' => $this->payableAccounts()];
    }

    private function payableAccounts()
    {
        return Account::whereIn('type', [AccountType::Cash, AccountType::Bank])->active()->orderBy('name')->get()
            ->mapWithKeys(fn ($a) => [$a->id => $a->displayName()]);
    }
}
