@extends('layouts.app')

@php use App\Support\Money; @endphp

@section('title', $partner->name)

@section('content')
    <div class="stat-grid">
        <div class="stat"><span>Sermaye girişi</span><strong>{{ Money::format($summary['capital']) }}</strong><small>Özkaynak, cariye dahil değil</small></div>
        <div class="stat"><span>Ortaktan alınan borç (net)</span><strong>{{ Money::format(\Brick\Math\BigDecimal::of($summary['loans_in'])->minus($summary['loan_repayments'])) }}</strong>
            <small>Alınan {{ Money::format($summary['loans_in']) }} · geri ödenen {{ Money::format($summary['loan_repayments']) }}</small></div>
        <div class="stat"><span>Ortağa ödemeler</span><strong>{{ Money::format($summary['payments']) }}</strong></div>
        <div class="stat">
            <span>Cari bakiye</span>
            <strong class="{{ str_starts_with($summary['balance'], '-') ? 'negative' : '' }}">{{ Money::format($summary['balance']) }}</strong>
            <small>{{ match (true) { str_starts_with($summary['balance'], '-') => 'Ortak şirkete borçlu', $summary['balance'] === '0.00' => 'Borç/alacak yok', default => 'Şirket ortağa borçlu' } }}</small>
        </div>
    </div>

    @can('write-finance')
        <div class="toolbar">
            <div class="actions">
                <a class="btn" href="{{ route('transactions.create', ['type' => 'inflow']) }}">Sermaye / borç girişi</a>
                <a class="btn" href="{{ route('transactions.create', ['type' => 'outflow']) }}">Ortağa ödeme</a>
                <a class="btn btn-secondary" href="{{ route('partners.edit', $partner) }}">Düzenle</a>
            </div>
        </div>
    @endcan

    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Tarih</th><th>İşlem</th><th>Açıklama</th><th class="num">Tutar</th><th class="num">Cari etkisi</th><th class="num">Cari bakiye</th>@can('write-finance')<th></th>@endcan</tr></thead>
            <tbody>
            @forelse ($ledger as $row)
                @php($m = $row['model'])
                <tr class="{{ $m->reversal_of_id || $m->reversal ? 'row-reversed' : '' }}">
                    <td>{{ $row['date']->format('d.m.Y') }}</td>
                    <td>
                        @if ($row['kind'] === 'cash')
                            <a href="{{ route('transactions.show', $m) }}">#{{ $m->id }}</a>
                        @endif
                        {{ $row['label'] }}
                        @if ($row['capital']) <span class="badge badge-muted">Sermaye</span> @endif
                    </td>
                    <td>{{ $row['description'] }}</td>
                    <td class="num">{{ Money::format($row['amount']) }}</td>
                    <td class="num {{ str_starts_with($row['effect'], '-') ? 'negative' : '' }}">{{ $row['capital'] ? '—' : Money::format($row['effect']) }}</td>
                    <td class="num">{{ Money::format($row['balance']) }}</td>
                    @can('write-finance')
                        <td class="num">
                            @if ($row['kind'] === 'adjustment' && ! $m->reversal_of_id && ! $m->reversal)
                                <details class="inline-details">
                                    <summary class="btn btn-secondary btn-sm">İptal</summary>
                                    <form method="POST" action="{{ route('partners.adjustments.reverse', $m) }}" class="stack-form">
                                        @csrf
                                        <div class="modal-title">Cari düzeltmeyi iptal et</div>
                                        <label>Neden <input name="reason" class="input input-sm" required minlength="3"></label>
                                        <button class="btn btn-danger btn-sm">Ters kayıt oluştur</button>
                                    </form>
                                </details>
                            @endif
                        </td>
                    @endcan
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Henüz cari hareket yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    @can('write-finance')
        <div class="card form-card section-gap">
            <h2 class="card-title">Nakitsiz cari düzeltme</h2>
            <p class="muted small">Kasaya/bankaya para girip çıkmadığı durumlar için (ör. ortak şirket giderini kendi cebinden ödedi). Nakit akış raporunu etkilemez.</p>
            <form method="POST" action="{{ route('partners.adjustments.store', $partner) }}">
                @csrf
                <div class="form-grid">
                    <div class="form-row">
                        <label for="adj-direction">Yön</label>
                        <select id="adj-direction" name="direction" required>
                            <option value="credit">Şirketin ortağa borcu artar (alacak)</option>
                            <option value="debit">Şirketin ortağa borcu azalır (borç)</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="adj-date">Tarih</label>
                        <input id="adj-date" type="date" name="date" class="input" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" required>
                    </div>
                    <div class="form-row">
                        <label for="adj-amount">Tutar (₺)</label>
                        <input id="adj-amount" name="amount" class="input num-input" inputmode="decimal" required>
                    </div>
                </div>
                <div class="form-row">
                    <label for="adj-description">Açıklama</label>
                    <input id="adj-description" name="description" class="input" required minlength="3" maxlength="1000">
                </div>
                <div class="form-actions"><button class="btn">Kaydet</button></div>
            </form>
        </div>
    @endcan
@endsection
