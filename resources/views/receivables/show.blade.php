@extends('layouts.app')

@php
    use App\Enums\AccountType;
    use App\Models\Receivable;
    use App\Support\Money;
    $r = $receivable;
    $status = $r->status();
    $remaining = $r->remainingAmount();
    $collected = $r->collectedAmount();
@endphp

@section('title', "Alacak · {$r->reference_no}")

@section('content')
    @if ($status === 'cancelled')
        <div class="alert alert-warning">Bu alacak {{ $r->cancelled_at->format('d.m.Y H:i') }} tarihinde iptal edildi: {{ $r->cancel_reason }}</div>
    @elseif ($r->isOverdue())
        <div class="alert alert-danger">Vade {{ (int) $r->due_date->diffInDays(today()) }} gün geçti. Kalan: {{ Money::format($remaining) }}</div>
    @endif

    <div class="stat-grid">
        <div class="stat"><span>Alacak tutarı</span><strong>{{ Money::format($r->amount) }}</strong></div>
        <div class="stat"><span>Tahsil edilen</span><strong>{{ Money::format($collected) }}</strong></div>
        <div class="stat"><span>Kalan</span><strong>{{ Money::format($status === 'cancelled' ? 0 : $remaining) }}</strong>
            <small>{{ Receivable::STATUS_LABELS[$status] }}</small></div>
    </div>

    <div class="detail-grid">
        <div class="card">
            <dl class="details">
                <dt>Müşteri</dt><dd><a href="{{ route('customers.show', $r->customer) }}">{{ $r->customer->name }}</a></dd>
                <dt>Poliçe / referans</dt><dd>{{ $r->reference_no }}</dd>
                <dt>Sigorta şirketi</dt><dd>{{ $r->insurance_company ?? '—' }}</dd>
                <dt>Alacak tarihi</dt><dd>{{ $r->issue_date->format('d.m.Y') }}</dd>
                <dt>Vade</dt><dd>{{ $r->due_date->format('d.m.Y') }}</dd>
                <dt>Açıklama</dt><dd>{{ $r->description ?? '—' }}</dd>
            </dl>
            @can('write-finance')
                @if ($status !== 'cancelled')
                    <div class="form-actions">
                        <a class="btn btn-secondary" href="{{ route('receivables.edit', $r) }}">Düzenle</a>
                        @if (\Brick\Math\BigDecimal::of($collected)->isZero())
                            <details class="inline-details">
                                <summary class="btn btn-danger">Alacağı iptal et</summary>
                                <form method="POST" action="{{ route('receivables.cancel', $r) }}" class="stack-form">
                                    @csrf
                                    <div class="modal-title">Alacağı iptal et</div>
                                    <small class="muted">Kayıt silinmez, iptal olarak işaretlenir ve raporlardan çıkar.</small>
                                    <label>Neden <input name="reason" class="input input-sm" required minlength="3"></label>
                                    <button class="btn btn-danger btn-sm">İptal et</button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endif
            @endcan
        </div>

        @can('write-finance')
            @if (in_array($status, ['open', 'partial'], true))
                <div class="card">
                    <h2 class="card-title">Tahsilat gir</h2>
                    <form method="POST" action="{{ route('receivables.collect', $r) }}">
                        @csrf
                        <div class="form-row">
                            <label for="c-amount">Tutar (₺)</label>
                            <input id="c-amount" name="amount" class="input num-input" inputmode="decimal" required value="{{ old('amount', Money::format($remaining, false)) }}">
                            <small class="muted">En fazla {{ Money::format($remaining) }}. Daha az girilirse kısmi tahsilat olur.</small>
                        </div>
                        <div class="form-row">
                            <label for="c-date">Tahsilat tarihi</label>
                            <input id="c-date" type="date" name="date" class="input" value="{{ old('date', today()->toDateString()) }}" min="{{ $r->issue_date->toDateString() }}" max="{{ today()->toDateString() }}" required>
                        </div>
                        <div class="form-row">
                            <label for="c-account">Tahsil edilen hesap</label>
                            <select id="c-account" name="account_id" required>
                                <option value="">Seçin</option>
                                @foreach ($accounts as $a)
                                    <option value="{{ $a->id }}" @selected((string) old('account_id') === (string) $a->id)>{{ $a->displayName() }} · {{ $a->type->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="c-pos">Valör tarihi <span class="muted">(yalnızca POS ile tahsilatta)</span></label>
                            <input id="c-pos" type="date" name="pos_settlement_date" class="input" value="{{ old('pos_settlement_date') }}">
                        </div>
                        <div class="form-row">
                            <label for="c-ref">Belge / dekont no</label>
                            <input id="c-ref" name="reference_no" class="input" maxlength="100" value="{{ old('reference_no') }}">
                        </div>
                        <button class="btn btn-block">Tahsilatı kaydet</button>
                    </form>
                </div>
            @endif
        @endcan
    </div>

    <h2 class="section-title">Tahsilatlar</h2>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>#</th><th>Tarih</th><th>Hesap</th><th>Açıklama</th><th class="num">Tutar</th></tr></thead>
            <tbody>
            @forelse ($r->collections as $t)
                <tr class="{{ $t->isReversal() || $t->reversal ? 'row-reversed' : '' }}">
                    <td><a href="{{ route('transactions.show', $t) }}">{{ $t->id }}</a></td>
                    <td>{{ $t->date->format('d.m.Y') }}</td>
                    <td>{{ $t->toAccount->name }}</td>
                    <td>{{ $t->description }}
                        @if ($t->isReversal()) <span class="badge badge-danger">Ters kayıt</span>
                        @elseif ($t->reversal) <span class="badge badge-muted">İptal edildi</span> @endif
                    </td>
                    <td class="num {{ str_starts_with((string) $t->amount, '-') ? 'negative' : '' }}">{{ Money::format($t->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Henüz tahsilat yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <p class="muted small">Bir tahsilatı iptal etmek için hareket numarasına tıklayıp "Ters kayıt oluştur"u kullanın; kalan tutar otomatik güncellenir.</p>
@endsection
