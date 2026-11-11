<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Partner;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\View\View;

class TransactionController extends Controller
{
    private const SORTABLE = ['date', 'amount', 'id'];

    public function __construct(private readonly TransactionService $service) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', new Enum(TransactionType::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'account_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['effective', 'all'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);

        $type = isset($filters['type']) ? TransactionType::from($filters['type']) : null;

        $query = Transaction::query()
            ->with(['category.parent', 'fromAccount', 'toAccount', 'reversal', 'partner'])
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($filters['account_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->where('from_account_id', $id)->orWhere('to_account_id', $id)))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->where('category_id', $id)->orWhereIn('category_id', Category::where('parent_id', $id)->select('id'))))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], Str::lower($term)).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(description) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(counterparty) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(reference_no) LIKE ?', [$like]));
            })
            ->when(($filters['status'] ?? 'effective') === 'effective', fn ($q) => $q->effective());

        // Toplam, filtrelenmiş kümenin tamamı üzerinden veritabanında hesaplanır (sayfa bazlı değil).
        $total = (clone $query)->reorder()->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,2)::text AS total')->value('total');

        $transactions = $query
            ->orderBy($filters['sort'] ?? 'date', $filters['dir'] ?? 'desc')
            ->orderBy('id', 'desc')
            ->paginate(25)
            ->withQueryString();

        return view('transactions.index', [
            'transactions' => $transactions,
            'type' => $type,
            'filters' => $filters,
            'total' => $total,
            'accounts' => Account::orderBy('name')->pluck('name', 'id'),
            'categories' => $type && in_array($type, [TransactionType::Inflow, TransactionType::Outflow], true)
                ? Category::where('direction', $type->value)->orderBy('sort')->pluck('name', 'id')
                : collect(),
        ]);
    }

    public function create(Request $request): View
    {
        $type = TransactionType::tryFrom((string) $request->query('type')) ?? TransactionType::Inflow;

        return view('transactions.form', $this->formData($type, new Transaction(['type' => $type, 'date' => today()])));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', new Enum(TransactionType::class)],
            'date' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:30'],
            'from_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'to_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'category_id' => ['nullable', 'integer'],
            'counterparty' => ['nullable', 'string', 'max:150'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'pos_settlement_date' => ['nullable', 'date'],
            'partner_id' => ['nullable', 'integer'],
        ], [], $this->attributes());

        $transaction = $this->service->create($data, $request->user());

        return redirect()
            ->route('transactions.index', ['type' => $transaction->type->value])
            ->with('status', "#{$transaction->id} numaralı {$transaction->type->label()} kaydedildi.");
    }

    public function show(Transaction $transaction): View
    {
        $transaction->load(['category.parent', 'fromAccount', 'toAccount', 'creator', 'reversal', 'reversalOf', 'settles', 'activeSettlement', 'partner', 'loan', 'recurringExpense', 'receivable']);

        return view('transactions.show', [
            'transaction' => $transaction,
            'history' => auth()->user()->can('view-audit')
                ? AuditLog::where('auditable_type', 'Transaction')->where('auditable_id', $transaction->id)->orderBy('id')->get()
                : collect(),
        ]);
    }

    public function edit(Transaction $transaction): View
    {
        return view('transactions.form', $this->formData($transaction->type, $transaction));
    }

    public function update(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate([
            'category_id' => ['nullable', 'integer'],
            'counterparty' => ['nullable', 'string', 'max:150'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [], $this->attributes());

        if (! in_array($transaction->type, [TransactionType::Inflow, TransactionType::Outflow], true)) {
            unset($data['category_id']);
        }

        $this->service->update($transaction, $data, $request->user());

        return redirect()->route('transactions.show', $transaction)->with('status', 'Hareket güncellendi.');
    }

    public function reverse(Request $request, Transaction $transaction): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ], [], ['date' => 'İptal tarihi', 'reason' => 'İptal nedeni']);

        $reversal = $this->service->reverse($transaction, $data['date'], $data['reason'], $request->user());

        return redirect()->route('transactions.show', $transaction)->with('status', "Hareket iptal edildi (ters kayıt #{$reversal->id}).");
    }

    /** @return array<string, mixed> */
    private function formData(TransactionType $type, Transaction $transaction): array
    {
        $accounts = Account::active()->orderBy('name')->get();

        return [
            'type' => $type,
            'transaction' => $transaction,
            'categories' => in_array($type, [TransactionType::Inflow, TransactionType::Outflow], true)
                ? Category::groupedOptions($type->value)
                : collect(),
            // Çıkış ve transfer kaynağı olarak POS seçilemez (transfer dışında POS'tan ödeme yapılmaz).
            'fromAccounts' => $accounts->filter(fn ($a) => $type === TransactionType::Transfer || $a->type !== AccountType::Pos)
                ->mapWithKeys(fn ($a) => [$a->id => $a->displayName().' · '.$a->type->label()]),
            'toAccounts' => $accounts->filter(fn ($a) => $type === TransactionType::Inflow || $type === TransactionType::Opening || $a->type !== AccountType::Pos)
                ->mapWithKeys(fn ($a) => [$a->id => $a->displayName().' · '.$a->type->label()]),
            'posAccountIds' => $accounts->where('type', AccountType::Pos)->pluck('id')->values(),
            'partners' => Partner::active()->orderBy('name')->pluck('name', 'id'),
        ];
    }

    /** @return array<string, string> */
    private function attributes(): array
    {
        return [
            'type' => 'İşlem türü', 'date' => 'Tarih', 'amount' => 'Tutar', 'from_account_id' => 'Kaynak hesap',
            'to_account_id' => 'Hedef hesap', 'category_id' => 'Kategori', 'counterparty' => 'İlgili kişi/kurum',
            'reference_no' => 'Belge/referans no', 'description' => 'Açıklama', 'pos_settlement_date' => 'Valör tarihi', 'partner_id' => 'Ortak',
        ];
    }
}
