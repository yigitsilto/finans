@extends('layouts.app')

@php
    use App\Support\Money;
    $editing = $expense->exists;
@endphp

@section('title', $editing ? 'Gider tanımını düzenle' : 'Yeni tekrarlayan gider')

@section('content')
    <div class="card form-card">
        @if ($editing)
            <div class="alert alert-info">Değişiklikler yalnızca bundan sonraki ödemeleri etkiler; geçmiş ödemeler değişmez.</div>
        @endif
        <form method="POST" action="{{ $editing ? route('expenses.update', $expense) : route('expenses.store') }}">
            @csrf
            @if ($editing) @method('PUT') @endif
            <div class="form-grid">
                <div class="form-row">
                    <label for="name">Gider adı</label>
                    <input id="name" name="name" class="input" value="{{ old('name', $expense->name) }}" required maxlength="120" placeholder="ör. Ofis kirası">
                </div>
                <div class="form-row">
                    <label for="category_id">Kategori</label>
                    <select id="category_id" name="category_id" required>
                        <option value="">Seçin</option>
                        @foreach ($categories as $group => $options)
                            <optgroup label="{{ $group }}">
                                @foreach ($options as $id => $name)
                                    <option value="{{ $id }}" @selected((string) old('category_id', $expense->category_id) === (string) $id)>{{ $name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="amount">Aylık tutar (₺)</label>
                    <input id="amount" name="amount" class="input num-input" inputmode="decimal" required
                           value="{{ old('amount', $expense->amount ? Money::format($expense->amount, false) : '') }}">
                </div>
                <div class="form-row">
                    <label for="day_of_month">Ödeme günü</label>
                    <input id="day_of_month" name="day_of_month" type="number" min="1" max="31" class="input" value="{{ old('day_of_month', $expense->day_of_month) }}" required>
                    <small class="muted">Kısa aylarda ayın son günü kullanılır.</small>
                </div>
                <div class="form-row">
                    <label for="start_date">Başlangıç</label>
                    <input id="start_date" name="start_date" type="date" class="input" value="{{ old('start_date', $expense->start_date?->toDateString()) }}" required>
                </div>
                <div class="form-row">
                    <label for="end_date">Bitiş <span class="muted">(opsiyonel)</span></label>
                    <input id="end_date" name="end_date" type="date" class="input" value="{{ old('end_date', $expense->end_date?->toDateString()) }}">
                </div>
                <div class="form-row">
                    <label for="account_id">Varsayılan ödeme hesabı</label>
                    <select id="account_id" name="account_id">
                        <option value="">—</option>
                        @foreach ($accounts as $id => $name)
                            <option value="{{ $id }}" @selected((string) old('account_id', $expense->account_id) === (string) $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="counterparty">Alıcı <span class="muted">(ör. ev sahibi, yönetim)</span></label>
                    <input id="counterparty" name="counterparty" class="input" value="{{ old('counterparty', $expense->counterparty) }}" maxlength="150">
                </div>
            </div>
            @if ($editing)
                <div class="form-row checkbox-row">
                    <label>
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $expense->is_active))> Aktif
                    </label>
                </div>
            @endif
            <div class="form-actions">
                <a href="{{ route('expenses.index') }}" class="btn btn-secondary">Vazgeç</a>
                <button class="btn">{{ $editing ? 'Kaydet' : 'Oluştur' }}</button>
            </div>
        </form>
    </div>
@endsection
