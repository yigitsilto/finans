@extends('layouts.app')

@php use App\Support\Money; @endphp

@section('title', 'Krediler')

@section('content')
    <div class="toolbar">
        <p class="muted">Kredi kullanımı nakit girişi, taksitler ise anapara ve faiz olarak ayrı nakit çıkışlarıdır (finansman faaliyeti).</p>
        @can('write-finance')
            <a class="btn" href="{{ route('loans.create') }}">Yeni kredi</a>
        @endcan
    </div>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Kredi</th><th>Banka</th><th>Başlangıç</th><th class="num">Faiz</th><th class="num">Ana para</th>
                <th class="num">Kalan anapara</th><th>Taksit</th><th>Sonraki taksit</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($loans as $loan)
                @php($s = $summaries[$loan->id])
                <tr>
                    <td><a href="{{ route('loans.show', $loan) }}">{{ $loan->name }}</a></td>
                    <td>{{ $loan->bank_name }}</td>
                    <td>{{ $loan->start_date->format('d.m.Y') }}</td>
                    <td class="num">%{{ rtrim(rtrim(str_replace('.', ',', $loan->annual_interest_rate), '0'), ',') }}</td>
                    <td class="num">{{ Money::format($loan->principal) }}</td>
                    <td class="num">{{ Money::format($s['remaining_principal']) }}</td>
                    <td>{{ $s['paid_count'] }} / {{ $s['total_count'] }}
                        @if ($s['closed']) <span class="badge badge-success">Kapandı</span> @endif
                    </td>
                    <td>
                        @if ($s['next'])
                            {{ $s['next']->due_date->format('d.m.Y') }} · {{ Money::format($s['next']->total) }}
                            @if ($s['next']->due_date->lt(today())) <span class="badge badge-danger">Gecikti</span> @endif
                        @else — @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Kayıtlı kredi yok.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endsection
