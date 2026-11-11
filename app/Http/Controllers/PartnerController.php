<?php

namespace App\Http\Controllers;

use App\Models\Partner;
use App\Models\PartnerAdjustment;
use App\Services\AuditService;
use App\Services\PartnerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PartnerController extends Controller
{
    public function __construct(private readonly PartnerService $partners) {}

    public function index(): View
    {
        $partners = Partner::orderByDesc('is_active')->orderBy('name')->get();

        return view('partners.index', ['partners' => $partners, 'summaries' => $this->partners->summaries($partners)]);
    }

    public function show(Partner $partner): View
    {
        return view('partners.show', [
            'partner' => $partner,
            'summary' => $this->partners->summary($partner),
            'ledger' => $this->partners->ledger($partner),
        ]);
    }

    public function create(): View
    {
        return view('partners.form', ['partner' => new Partner(['is_active' => true])]);
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request);

        $partner = DB::transaction(function () use ($data, $request, $audit) {
            $partner = new Partner($data);
            $partner->created_by = $request->user()->id;
            $partner->is_active = true;
            $partner->save();
            $audit->log(AuditService::CREATED, $partner, new: $partner->only(['name', 'share_percent']));

            return $partner;
        });

        return redirect()->route('partners.show', $partner)->with('status', 'Ortak eklendi.');
    }

    public function edit(Partner $partner): View
    {
        return view('partners.form', ['partner' => $partner]);
    }

    public function update(Request $request, Partner $partner, AuditService $audit): RedirectResponse
    {
        $data = $this->validated($request, $partner);

        DB::transaction(function () use ($partner, $data, $request, $audit) {
            $original = $partner->getOriginal();
            $partner->fill($data);
            $partner->is_active = $request->boolean('is_active');
            $partner->save();
            [$old, $new] = AuditService::diff($original, $partner);
            if ($new !== []) {
                $audit->log(AuditService::UPDATED, $partner, $old, $new);
            }
        });

        return redirect()->route('partners.show', $partner)->with('status', 'Ortak güncellendi.');
    }

    public function storeAdjustment(Request $request, Partner $partner): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'direction' => ['required', Rule::in(['credit', 'debit'])],
            'amount' => ['required', 'string', 'max:30'],
            'description' => ['required', 'string', 'min:3', 'max:1000'],
        ], [], ['date' => 'Tarih', 'direction' => 'Yön', 'amount' => 'Tutar', 'description' => 'Açıklama']);

        $this->partners->addAdjustment($partner, $data, $request->user());

        return redirect()->route('partners.show', $partner)->with('status', 'Cari düzeltme kaydı eklendi.');
    }

    public function reverseAdjustment(Request $request, PartnerAdjustment $adjustment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']], [], ['reason' => 'İptal nedeni']);

        $this->partners->reverseAdjustment($adjustment, $data['reason'], $request->user());

        return redirect()->route('partners.show', $adjustment->partner_id)->with('status', 'Cari düzeltme kaydı iptal edildi.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Partner $partner = null): array
    {
        if ($request->filled('share_percent')) {
            $request->merge(['share_percent' => str_replace(',', '.', $request->input('share_percent'))]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('partners', 'name')->ignore($partner?->id)],
            'share_percent' => ['nullable', 'decimal:0,2', 'gt:0', 'lte:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['name' => 'Ad soyad', 'share_percent' => 'Ortaklık payı', 'notes' => 'Notlar']);
    }
}
