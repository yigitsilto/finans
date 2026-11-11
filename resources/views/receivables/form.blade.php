@extends('layouts.app')

@php
    use App\Support\Money;
    $editing = $receivable->exists;
@endphp

@section('title', $editing ? 'Alacağı düzenle' : 'Yeni alacak')

@section('content')
    <div class="card form-card">
        @if (! $editing && $customers->isEmpty())
            <div class="alert alert-info">Önce bir müşteri ekleyin. <a href="{{ route('customers.create', ['return_to' => 'receivable']) }}">Yeni müşteri</a></div>
        @endif
        <form method="POST" action="{{ $editing ? route('receivables.update', $receivable) : route('receivables.store') }}">
            @csrf
            @if ($editing) @method('PUT') @endif
            <div class="form-grid">
                <div class="form-row">
                    <label for="customer_id">Müşteri</label>
                    @if ($editing)
                        <input class="input" value="{{ $receivable->customer->name }}" disabled>
                    @else
                        <select id="customer_id" name="customer_id" required>
                            <option value="">Seçin</option>
                            @foreach ($customers as $id => $name)
                                <option value="{{ $id }}" @selected((string) old('customer_id', $receivable->customer_id) === (string) $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                        <small><a href="{{ route('customers.create', ['return_to' => 'receivable']) }}">+ Yeni müşteri</a></small>
                    @endif
                </div>
                <div class="form-row">
                    <label for="reference_no">Poliçe / referans no</label>
                    <input id="reference_no" name="reference_no" class="input" value="{{ old('reference_no', $receivable->reference_no) }}" required maxlength="100">
                </div>
                <div class="form-row">
                    <label for="insurance_company">Sigorta şirketi</label>
                    <input id="insurance_company" name="insurance_company" class="input" value="{{ old('insurance_company', $receivable->insurance_company) }}" maxlength="120">
                </div>
                <div class="form-row">
                    <label for="amount">Alacak tutarı (₺)</label>
                    <input id="amount" name="amount" class="input num-input" inputmode="decimal" required
                           value="{{ old('amount', $receivable->amount ? Money::format($receivable->amount, false) : '') }}">
                    @if ($editing)
                        <small class="muted">Tahsil edilen tutarın ({{ Money::format($receivable->collectedAmount()) }}) altına düşürülemez.</small>
                    @endif
                </div>
                <div class="form-row">
                    <label for="issue_date">Alacak tarihi</label>
                    <input id="issue_date" name="issue_date" type="date" class="input" value="{{ old('issue_date', $receivable->issue_date?->toDateString()) }}" max="{{ today()->toDateString() }}" required>
                </div>
                <div class="form-row">
                    <label for="due_date">Vade tarihi</label>
                    <input id="due_date" name="due_date" type="date" class="input" value="{{ old('due_date', $receivable->due_date?->toDateString()) }}" required>
                </div>
            </div>
            <div class="form-row">
                <label for="description">Açıklama</label>
                <textarea id="description" name="description" rows="2" maxlength="1000">{{ old('description', $receivable->description) }}</textarea>
            </div>
            <div class="form-actions">
                <a href="{{ $editing ? route('receivables.show', $receivable) : route('receivables.index') }}" class="btn btn-secondary">Vazgeç</a>
                <button class="btn">{{ $editing ? 'Kaydet' : 'Oluştur' }}</button>
            </div>
        </form>
    </div>
@endsection
