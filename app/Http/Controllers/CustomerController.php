<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
        ]);

        $outstanding = DB::table('receivables as r')
            ->selectRaw('CAST(COALESCE(SUM(r.amount - COALESCE((SELECT SUM(t.amount) FROM transactions t WHERE t.receivable_id = r.id), 0)), 0) AS DECIMAL(18,2))')
            ->whereColumn('r.customer_id', 'customers.id')
            ->whereNull('r.cancelled_at');

        $customers = Customer::query()
            ->select('customers.*')
            ->selectSub($outstanding, 'outstanding')
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->whereRaw('LOWER(name) LIKE ?', ['%'.str_replace(['%', '_'], ['\%', '\_'], Str::lower($term)).'%']);
            })
            ->when(($filters['status'] ?? 'active') !== 'all', fn ($q) => $q->where('is_active', ($filters['status'] ?? 'active') === 'active'))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('customers.index', ['customers' => $customers, 'filters' => $filters]);
    }

    public function show(Customer $customer): View
    {
        $receivables = $customer->receivables()->withCollected()->orderByDesc('due_date')->get();

        return view('customers.show', ['customer' => $customer, 'receivables' => $receivables]);
    }

    public function create(): View
    {
        return view('customers.form', ['customer' => new Customer]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);

        $customer = DB::transaction(function () use ($data, $request, $audit) {
            $customer = new Customer($data);
            $customer->created_by = $request->user()->id;
            $customer->save();
            $audit->log(AuditService::CREATED, $customer, new: $customer->only(['type', 'name', 'phone', 'email']));

            return $customer;
        });

        return redirect()->to($request->input('return_to') === 'receivable'
            ? route('receivables.create', ['customer_id' => $customer->id])
            : route('customers.show', $customer))->with('status', 'Müşteri eklendi.');
    }

    public function edit(Customer $customer): View
    {
        return view('customers.form', ['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($customer, $data, $request, $audit) {
            $original = $customer->getOriginal();
            $customer->fill($data);
            $customer->is_active = $request->boolean('is_active');
            $customer->save();
            [$old, $new] = AuditService::diff($original, $customer);
            unset($old['tax_number'], $new['tax_number']);
            if ($new !== []) {
                $audit->log(AuditService::UPDATED, $customer, $old, $new);
            }
        });

        return redirect()->route('customers.show', $customer)->with('status', 'Müşteri güncellendi.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $request->merge(['tax_number' => $request->filled('tax_number') ? preg_replace('/\D/', '', $request->input('tax_number')) : null]);

        return $request->validate([
            'type' => ['required', Rule::in(['individual', 'corporate'])],
            'name' => ['required', 'string', 'max:150'],
            'tax_number' => ['nullable', 'regex:/^\d{10,11}$/'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], ['tax_number.regex' => 'TCKN 11, VKN 10 haneli olmalıdır.'], [
            'type' => 'Müşteri türü', 'name' => 'Ad / unvan', 'tax_number' => 'TCKN / VKN', 'phone' => 'Telefon',
            'email' => 'E-posta', 'notes' => 'Notlar',
        ]);
    }
}
