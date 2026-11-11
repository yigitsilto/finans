@extends('layouts.app')

@php use App\Support\Money; @endphp

@section('title', $account->name)

@section('content')
    <div class="toolbar">
        <div class="meta">
            <span class="badge badge-muted">{{ $account->type->label() }}</span>
            @if ($account->bank_name) <span>{{ $account->bank_name }}</span> @endif
            @if ($account->maskedIban()) <span class="mono">{{ $account->maskedIban() }}</span> @endif
            @if ($account->settlementAccount) <span>Aktarım: {{ $account->settlementAccount->name }}</span> @endif
            @unless ($account->is_active) <span class="badge badge-muted">Pasif</span> @endunless
        </div>
        @can('write-finance')
            <a class="btn btn-secondary" href="{{ route('accounts.edit', $account) }}">Hesabı düzenle</a>
        @endcan
    </div>

    <form method="GET" class="filters card-filters">
        <label>Başlangıç <input type="date" name="from" class="input" value="{{ $from->toDateString() }}"></label>
        <label>Bitiş <input type="date" name="to" class="input" value="{{ $to->toDateString() }}"></label>
        <button class="btn btn-secondary">Göster</button>
    </form>

    <div class="table-wrap">
        <table class="table">
            <thead>
            <tr>
                <th>Tarih</th>
                <th>#</th>
                <th>İşlem</th>
                <th>Açıklama</th>
                <th class="num">Tutar</th>
                <th class="num">Bakiye</th>
            </tr>
            </thead>
            <tbody>
            <tr class="row-strong">
                <td>{{ $from->format('d.m.Y') }}</td>
                <td colspan="4">Devreden bakiye</td>
                <td class="num">{{ Money::format($opening) }}</td>
            </tr>
            @foreach ($lines as $line)
                @php($t = $line['transaction'])
                <tr class="{{ $t->isReversal() || $t->reversal ? 'row-reversed' : '' }}">
                    <td>{{ $t->date->format('d.m.Y') }}</td>
                    <td><a href="{{ route('transactions.show', $t) }}">{{ $t->id }}</a></td>
                    <td>
                        {{ $t->type->label() }}
                        @if ($t->category) <small class="muted d-block">{{ $t->category->fullName() }}</small> @endif
                    </td>
                    <td>{{ $t->description }}</td>
                    <td class="num {{ str_starts_with($line['signed'], '-') ? 'negative' : 'positive' }}">{{ Money::format($line['signed']) }}</td>
                    <td class="num">{{ Money::format($line['balance']) }}</td>
                </tr>
            @endforeach
            <tr class="row-strong">
                <td>{{ $to->format('d.m.Y') }}</td>
                <td colspan="4">Dönem sonu bakiye</td>
                <td class="num">{{ Money::format($closing) }}</td>
            </tr>
            </tbody>
        </table>
    </div>
@endsection
