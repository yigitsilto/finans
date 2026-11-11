<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\AuditService;
use App\Services\BalanceService;
use App\Services\TransactionService;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __construct(private readonly BalanceService $balances) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['cash', 'bank', 'pos', 'available'])],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
        ]);

        $type = $filters['type'] ?? null;
        $status = $filters['status'] ?? 'active';

        $accounts = Account::query()
            ->with('settlementAccount')
            ->when($type === 'available', fn ($q) => $q->whereIn('type', ['cash', 'bank']))
            ->when($type && $type !== 'available', fn ($q) => $q->where('type', $type))
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderByRaw("CASE type WHEN 'cash' THEN 1 WHEN 'bank' THEN 2 ELSE 3 END")
            ->orderBy('name')
            ->get();

        return view('accounts.index', [
            'accounts' => $accounts,
            'balances' => $this->balances->balances(),
            'summary' => $this->balances->summary(),
            'filters' => ['type' => $type, 'status' => $status],
        ]);
    }

    public function show(Request $request, Account $account): View
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($filters['from'] ?? today()->startOfMonth());
        $to = CarbonImmutable::parse($filters['to'] ?? today());

        $opening = $this->balances->balance($account, $from->subDay());

        $rows = Transaction::query()
            ->with(['category.parent', 'fromAccount', 'toAccount', 'reversal'])
            ->where(fn ($q) => $q->where('from_account_id', $account->id)->orWhere('to_account_id', $account->id))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')->orderBy('id')
            ->get();

        // Hesap ekstresi: yürüyen bakiye BigDecimal ile hesaplanır.
        $running = BigDecimal::of($opening);
        $lines = $rows->map(function (Transaction $t) use ($account, &$running) {
            $signed = $t->to_account_id === $account->id ? Money::of($t->amount) : Money::of($t->amount)->negated();
            $running = $running->plus($signed);

            return ['transaction' => $t, 'signed' => (string) $signed, 'balance' => (string) $running];
        });

        return view('accounts.show', [
            'account' => $account,
            'lines' => $lines,
            'opening' => $opening,
            'closing' => (string) Money::of($running),
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function create(): View
    {
        return view('accounts.form', [
            'account' => new Account(['type' => AccountType::Bank, 'currency' => 'TRY', 'is_active' => true]),
            'banks' => $this->bankOptions(),
        ]);
    }

    public function store(Request $request, TransactionService $transactions, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);
        $opening = $request->validate([
            'opening_amount' => ['nullable', 'string', 'max:30'],
            'opening_date' => ['nullable', 'required_with:opening_amount', 'date'],
        ]);

        $account = DB::transaction(function () use ($data, $opening, $request, $transactions, $audit) {
            $account = new Account($data);
            $account->type = AccountType::from($data['type']);
            $account->created_by = $request->user()->id;
            $account->save();

            $audit->log(AuditService::CREATED, $account, new: $account->only(['type', 'name', 'bank_name', 'iban', 'currency', 'settlement_account_id', 'allow_negative']));

            // Tutar biçimi ve > 0 kuralı TransactionService içinde doğrulanır.
            if (filled($opening['opening_amount'] ?? null)) {
                $transactions->create([
                    'type' => TransactionType::Opening->value,
                    'date' => $opening['opening_date'],
                    'amount' => $opening['opening_amount'],
                    'to_account_id' => $account->id,
                    'description' => 'Açılış / devir bakiyesi',
                ], $request->user());
            }

            return $account;
        });

        return redirect()->route('accounts.show', $account)->with('status', "{$account->name} hesabı oluşturuldu.");
    }

    public function edit(Account $account): View
    {
        return view('accounts.form', ['account' => $account, 'banks' => $this->bankOptions($account)]);
    }

    public function update(Request $request, Account $account, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, $account);
        unset($data['type'], $data['currency']); // tür ve para birimi sonradan değiştirilemez

        $isActive = $request->boolean('is_active');
        if (! $isActive && $account->is_active && ! BigDecimal::of($this->balances->balance($account))->isZero()) {
            throw ValidationException::withMessages(['is_active' => 'Bakiyesi sıfır olmayan hesap pasife alınamaz.']);
        }

        DB::transaction(function () use ($account, $data, $isActive, $audit) {
            $original = $account->getOriginal();
            $account->fill($data);
            $account->is_active = $isActive;
            $account->save();

            [$old, $new] = AuditService::diff($original, $account);
            if ($new !== []) {
                $audit->log(AuditService::UPDATED, $account, $old, $new);
            }
        });

        return redirect()->route('accounts.show', $account)->with('status', 'Hesap güncellendi.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Account $account = null): array
    {
        $request->merge(['iban' => $request->filled('iban') ? strtoupper(preg_replace('/\s+/', '', $request->input('iban'))) : null]);
        $type = $account?->type->value ?? $request->input('type');

        $data = $request->validate([
            'type' => [$account ? 'nullable' : 'required', new Enum(AccountType::class)],
            'name' => ['required', 'string', 'max:120', Rule::unique('accounts', 'name')->ignore($account?->id)],
            'bank_name' => [Rule::requiredIf(in_array($type, ['bank', 'pos'], true)), 'nullable', 'string', 'max:120'],
            'iban' => ['nullable', 'string', 'regex:/^TR\d{24}$/'],
            'currency' => [$account ? 'nullable' : 'required', Rule::in(['TRY'])],
            'settlement_account_id' => [Rule::requiredIf($type === 'pos'), 'nullable', Rule::exists('accounts', 'id')->where('type', 'bank')->where('is_active', true)],
            'allow_negative' => ['nullable', 'boolean'],
        ], [
            'iban.regex' => 'IBAN "TR" ile başlayan 26 karakter olmalıdır.',
            'settlement_account_id.required' => 'POS hesabının geçeceği banka hesabı seçilmelidir.',
            'bank_name.required' => 'Banka adı zorunludur.',
        ], ['bank_name' => 'Banka adı', 'settlement_account_id' => 'Aktarım bankası', 'iban' => 'IBAN', 'currency' => 'Para birimi', 'type' => 'Hesap türü']);

        if (! empty($data['iban']) && ! $this->validIbanChecksum($data['iban'])) {
            throw ValidationException::withMessages(['iban' => 'IBAN kontrol basamağı hatalı.']);
        }

        if ($type !== 'pos') {
            $data['settlement_account_id'] = null;
        }
        if ($type === 'cash') {
            $data['iban'] = null;
        }
        $data['allow_negative'] = $request->boolean('allow_negative') && $type === 'bank';

        return $data;
    }

    private function validIbanChecksum(string $iban): bool
    {
        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = preg_replace_callback('/[A-Z]/', fn ($m) => (string) (ord($m[0]) - 55), $rearranged);

        $remainder = 0;
        foreach (str_split($numeric) as $digit) {
            $remainder = ($remainder * 10 + (int) $digit) % 97;
        }

        return $remainder === 1;
    }

    /** @return array<int, string> */
    private function bankOptions(?Account $except = null): array
    {
        return Account::where('type', AccountType::Bank)->active()
            ->when($except, fn ($q) => $q->whereKeyNot($except->id))
            ->orderBy('name')->get()
            ->mapWithKeys(fn ($a) => [$a->id => $a->displayName()])->all();
    }
}
