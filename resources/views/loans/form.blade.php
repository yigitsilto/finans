@extends('layouts.app')

@section('title', 'Yeni kredi')

@section('content')
    <div class="card form-card">
        <form method="POST" action="{{ route('loans.store') }}">
            @csrf
            <div class="form-grid">
                <div class="form-row">
                    <label for="name">Kredi adı</label>
                    <input id="name" name="name" class="input" value="{{ old('name') }}" required maxlength="120" placeholder="ör. Garanti Ticari Kredi 2026">
                </div>
                <div class="form-row">
                    <label for="bank_name">Banka</label>
                    <input id="bank_name" name="bank_name" class="input" value="{{ old('bank_name') }}" required maxlength="120">
                </div>
                <div class="form-row">
                    <label for="account_id">Kullandırım / ödeme hesabı</label>
                    <select id="account_id" name="account_id" required>
                        <option value="">Seçin</option>
                        @foreach ($banks as $id => $label)
                            <option value="{{ $id }}" @selected((string) old('account_id') === (string) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="principal">Kredi tutarı (₺)</label>
                    <input id="principal" name="principal" class="input num-input" value="{{ old('principal') }}" required inputmode="decimal" placeholder="0,00">
                </div>
                <div class="form-row">
                    <label for="annual_interest_rate">Yıllık faiz (%)</label>
                    <input id="annual_interest_rate" name="annual_interest_rate" class="input num-input" value="{{ old('annual_interest_rate') }}" required inputmode="decimal" placeholder="ör. 42,5">
                </div>
                <div class="form-row">
                    <label for="term_months">Vade (ay)</label>
                    <input id="term_months" name="term_months" type="number" min="1" max="360" class="input" value="{{ old('term_months', 12) }}" required>
                </div>
                <div class="form-row">
                    <label for="start_date">Kullandırım tarihi</label>
                    <input id="start_date" name="start_date" type="date" class="input" value="{{ old('start_date', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required>
                </div>
                <div class="form-row">
                    <label for="first_due_date">İlk taksit tarihi</label>
                    <input id="first_due_date" name="first_due_date" type="date" class="input" value="{{ old('first_due_date', today()->addMonth()->toDateString()) }}" required>
                </div>
            </div>
            <div class="form-row checkbox-row">
                <label>
                    <input type="hidden" name="record_disbursement" value="0">
                    <input type="checkbox" name="record_disbursement" value="1" @checked(old('record_disbursement', true))>
                    Kredi tutarı hesaba yatırıldı: nakit girişi olarak kaydet ("Alınan krediler")
                </label>
            </div>
            <div class="form-row">
                <label for="notes">Notlar</label>
                <textarea id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes') }}</textarea>
            </div>
            <p class="muted small">
                Taksit planı eşit taksitli (annüite) yöntemle oluşturulur. Bankanın planı farklıysa (BSMV/KKDF, farklı vade günleri)
                ödenmemiş taksitleri kredi ekranında bankanın planına göre düzeltebilirsiniz.
            </p>
            <div class="form-actions">
                <a href="{{ route('loans.index') }}" class="btn btn-secondary">Vazgeç</a>
                <button class="btn">Krediyi oluştur</button>
            </div>
        </form>
    </div>
@endsection
