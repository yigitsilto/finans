@extends('layouts.app')

@php use App\Support\Money; @endphp

@section('title', 'Ortaklar Cari')

@section('content')
    <div class="toolbar">
        <p class="muted">Sermaye özkaynak olarak ayrı izlenir. Cari bakiye &gt; 0 ise şirket ortağa, &lt; 0 ise ortak şirkete borçludur. Ortak hareketleri gider değil, finansman faaliyetidir.</p>
        @can('write-finance')
            <a class="btn" href="{{ route('partners.create') }}">Yeni ortak</a>
        @endcan
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr><th>Ortak</th><th class="num">Pay</th><th class="num">Sermaye</th><th class="num">Ortaktan borç</th><th class="num">Ortağa ödemeler</th><th class="num">Cari bakiye</th><th>Durum</th></tr>
            </thead>
            <tbody>
            @forelse ($partners as $partner)
                @php($s = $summaries[$partner->id])
                <tr>
                    <td><a href="{{ route('partners.show', $partner) }}">{{ $partner->name }}</a></td>
                    <td class="num">{{ $partner->share_percent ? '%'.str_replace('.', ',', $partner->share_percent) : '—' }}</td>
                    <td class="num">{{ Money::format($s['capital']) }}</td>
                    <td class="num">{{ Money::format(\Brick\Math\BigDecimal::of($s['loans_in'])->minus($s['loan_repayments'])) }}</td>
                    <td class="num">{{ Money::format($s['payments']) }}</td>
                    <td class="num {{ str_starts_with($s['balance'], '-') ? 'negative' : '' }}">{{ Money::format($s['balance']) }}</td>
                    <td><span class="badge {{ $partner->is_active ? 'badge-success' : 'badge-muted' }}">{{ $partner->is_active ? 'Aktif' : 'Pasif' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="7" class="muted">Kayıtlı ortak yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
