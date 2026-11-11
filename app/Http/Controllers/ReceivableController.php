<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Receivable;
use App\Services\ReceivableService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReceivableController extends Controller
{
    /** Tahsil edilen tutarın SQL ifadesi (ters kayıtlar negatif olduğundan düz SUM). */
    private const COLLECTED_SQL = '(SELECT COALESCE(SUM(t.amount), 0) FROM transactions t WHERE t.receivable_id = receivables.id)';

    private const SORTABLE = ['due_date', 'issue_date', 'amount'];

    public function __construct(private readonly ReceivableService $service) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'customer_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['outstanding', 'overdue', 'open', 'partial', 'paid', 'cancelled', 'all'])],
            'due_from' => ['nullable', 'date'],
            'due_to' => ['nullable', 'date', 'after_or_equal:due_from'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ]);
        $filters['status'] ??= 'outstanding';

        $base = $this->filteredQuery($filters);

        $totals = DB::query()->fromSub(
            (clone $base)->reorder()->select('receivables.amount')->selectRaw(self::COLLECTED_SQL.' AS collected')->whereNull('cancelled_at'),
            'f'
        )->selectRaw('COALESCE(SUM(amount), 0)::numeric(18,2)::text AS amount, COALESCE(SUM(collected), 0)::numeric(18,2)::text AS collected,
            COALESCE(SUM(amount - collected), 0)::numeric(18,2)::text AS remaining, COUNT(*) AS count')->first();

        $receivables = $base->with('customer')->select('receivables.*')->withCollected()
            ->orderBy($filters['sort'] ?? 'due_date', $filters['dir'] ?? 'asc')->orderBy('id')
            ->paginate(25)->withQueryString();

        return view('receivables.index', [
            'receivables' => $receivables,
            'filters' => $filters,
            'totals' => $totals,
            'customers' => Customer::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function show(Receivable $receivable): View
    {
        $receivable->load(['customer', 'collections.toAccount', 'collections.reversal']);

        return view('receivables.show', [
            'receivable' => $receivable,
            'accounts' => Account::active()->orderBy('name')->get(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('receivables.form', [
            'receivable' => new Receivable([
                'customer_id' => $request->integer('customer_id') ?: null,
                'issue_date' => today(),
                'due_date' => today()->addDays(30),
            ]),
            'customers' => Customer::active()->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $receivable = $this->service->create($this->validated($request, true), $request->user());

        return redirect()->route('receivables.show', $receivable)->with('status', 'Alacak kaydedildi.');
    }

    public function edit(Receivable $receivable): View
    {
        return view('receivables.form', [
            'receivable' => $receivable,
            'customers' => Customer::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function update(Request $request, Receivable $receivable): RedirectResponse
    {
        $this->service->update($receivable, $this->validated($request, false), $request->user());

        return redirect()->route('receivables.show', $receivable)->with('status', 'Alacak güncellendi.');
    }

    public function collect(Request $request, Receivable $receivable): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:30'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'pos_settlement_date' => ['nullable', 'date'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
        ], [], ['date' => 'Tahsilat tarihi', 'amount' => 'Tutar', 'account_id' => 'Hesap', 'pos_settlement_date' => 'Valör tarihi']);

        $this->service->collect($receivable, $data, $request->user());

        return redirect()->route('receivables.show', $receivable)->with('status', 'Tahsilat kaydedildi.');
    }

    public function cancel(Request $request, Receivable $receivable): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'İptal nedeni']);

        $this->service->cancel($receivable, $data['reason'], $request->user());

        return redirect()->route('receivables.show', $receivable)->with('status', 'Alacak iptal edildi.');
    }

    private function filteredQuery(array $filters): Builder
    {
        $collected = self::COLLECTED_SQL;

        return Receivable::query()
            ->when($filters['q'] ?? null, function ($q, $term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], Str::lower($term)).'%';
                $q->where(fn ($w) => $w->whereRaw('LOWER(reference_no) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(insurance_company, \'\')) LIKE ?', [$like])
                    ->orWhereHas('customer', fn ($c) => $c->whereRaw('LOWER(name) LIKE ?', [$like])));
            })
            ->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($filters['due_from'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '>=', $d))
            ->when($filters['due_to'] ?? null, fn ($q, $d) => $q->whereDate('due_date', '<=', $d))
            ->when($filters['status'], fn ($q, $status) => match ($status) {
                'outstanding' => $q->whereNull('cancelled_at')->whereRaw("{$collected} < receivables.amount"),
                'overdue' => $q->whereNull('cancelled_at')->whereRaw("{$collected} < receivables.amount")->whereDate('due_date', '<', today()),
                'open' => $q->whereNull('cancelled_at')->whereRaw("{$collected} = 0"),
                'partial' => $q->whereNull('cancelled_at')->whereRaw("{$collected} > 0 AND {$collected} < receivables.amount"),
                'paid' => $q->whereNull('cancelled_at')->whereRaw("{$collected} >= receivables.amount"),
                'cancelled' => $q->whereNotNull('cancelled_at'),
                default => $q,
            });
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'customer_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'reference_no' => ['required', 'string', 'max:100'],
            'insurance_company' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'amount' => ['required', 'string', 'max:30'],
            'issue_date' => ['required', 'date'],
            'due_date' => ['required', 'date'],
        ], [], [
            'customer_id' => 'Müşteri', 'reference_no' => 'Poliçe / referans no', 'insurance_company' => 'Sigorta şirketi',
            'description' => 'Açıklama', 'amount' => 'Alacak tutarı', 'issue_date' => 'Alacak tarihi', 'due_date' => 'Vade tarihi',
        ]);
    }
}
