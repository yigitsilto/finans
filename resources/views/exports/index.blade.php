@extends('layouts.app')

@php
    $monthStart = today()->startOfMonth()->toDateString();
    $today = today()->toDateString();
    $formats = \App\Http\Controllers\ExportController::FORMATS;
@endphp

@section('title', 'Excel / PDF Raporları')

@section('content')
    @if ($errors->has('export'))
        <div class="alert alert-danger">{{ $errors->first('export') }}</div>
    @endif

    <div class="export-grid">
        @foreach ($reports as $key => $title)
            <form method="GET" class="card export-card" data-report="{{ $key }}">
                <h2 class="card-title">{{ $title }}</h2>
                @switch($key)
                    @case('cashflow')
                    @case('expenses')
                        <div class="form-grid">
                            <label class="form-row">Başlangıç <input type="date" name="from" class="input" value="{{ $monthStart }}" required></label>
                            <label class="form-row">Bitiş <input type="date" name="to" class="input" value="{{ $today }}" required></label>
                        </div>
                        @break
                    @case('aging')
                        <div class="form-grid">
                            <label class="form-row">Rapor tarihi <input type="date" name="date" class="input" value="{{ $today }}" max="{{ $today }}" required></label>
                            <label class="form-row">Müşteri
                                <select name="customer_id"><option value="">Tümü</option>
                                    @foreach ($customers as $id => $name) <option value="{{ $id }}">{{ $name }}</option> @endforeach
                                </select>
                            </label>
                        </div>
                        @break
                    @case('transactions')
                        <div class="form-grid">
                            <label class="form-row">Başlangıç <input type="date" name="from" class="input" value="{{ $monthStart }}"></label>
                            <label class="form-row">Bitiş <input type="date" name="to" class="input" value="{{ $today }}"></label>
                            <label class="form-row">Tür
                                <select name="type"><option value="">Tümü</option>
                                    @foreach (\App\Enums\TransactionType::cases() as $case) <option value="{{ $case->value }}">{{ $case->label() }}</option> @endforeach
                                </select>
                            </label>
                            <label class="form-row">Hesap
                                <select name="account_id"><option value="">Tümü</option>
                                    @foreach ($accounts as $id => $name) <option value="{{ $id }}">{{ $name }}</option> @endforeach
                                </select>
                            </label>
                        </div>
                        @break
                    @case('audit')
                        <div class="form-grid">
                            <label class="form-row">Başlangıç <input type="date" name="from" class="input" value="{{ $monthStart }}"></label>
                            <label class="form-row">Bitiş <input type="date" name="to" class="input" value="{{ $today }}"></label>
                        </div>
                        @break
                @endswitch
                <div class="form-actions">
                    @foreach ($formats as $format => $label)
                        <button class="btn {{ $format === 'pdf' ? 'btn-secondary' : '' }}" formaction="{{ route('exports.download', ['report' => $key, 'format' => $format]) }}">{{ $label }}</button>
                    @endforeach
                </div>
            </form>
        @endforeach
    </div>

    <p class="muted small">
        Excel'de tutarlar sayısal hücre ve TL biçimiyle yazılır; toplam satırları ve filtreler dosyada yer alır. PDF, Türkçe karakterler için DejaVu Sans yazı tipini
        gömer ve en fazla {{ number_format(\App\Reports\PdfRenderer::MAX_ROWS, 0, ',', '.') }} satır içerir. Her indirme denetim kaydına yazılır.
    </p>
@endsection
