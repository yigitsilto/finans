@extends('layouts.app')

@php($editing = $account->exists)

@section('title', $editing ? 'Hesabı düzenle' : 'Yeni hesap')

@section('content')
    <div class="card form-card">
        <form method="POST" action="{{ $editing ? route('accounts.update', $account) : route('accounts.store') }}">
            @csrf
            @if ($editing) @method('PUT') @endif

            <div class="form-grid">
                <div class="form-row">
                    <label for="type">Hesap türü</label>
                    @if ($editing)
                        <input class="input" value="{{ $account->type->label() }}" disabled>
                        <small class="muted">Hesap türü sonradan değiştirilemez.</small>
                    @else
                        <select id="type" name="type" required>
                            @foreach (\App\Enums\AccountType::cases() as $case)
                                <option value="{{ $case->value }}" @selected(old('type', $account->type?->value) === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    @endif
                </div>
                <div class="form-row">
                    <label for="name">Hesap adı</label>
                    <input id="name" name="name" class="input" value="{{ old('name', $account->name) }}" required maxlength="120" placeholder="ör. Merkez Kasa, Garanti Vadesiz">
                </div>
                <div class="form-row">
                    <label for="bank_name">Banka adı <span class="muted">(banka ve POS için)</span></label>
                    <input id="bank_name" name="bank_name" class="input" value="{{ old('bank_name', $account->bank_name) }}" maxlength="120">
                </div>
                <div class="form-row">
                    <label for="iban">IBAN <span class="muted">(opsiyonel, şifreli saklanır)</span></label>
                    <input id="iban" name="iban" class="input mono" value="{{ old('iban', $account->iban) }}" maxlength="40" placeholder="TR00 0000 0000 0000 0000 0000 00" autocomplete="off">
                </div>
                <div class="form-row">
                    <label for="settlement_account_id">POS aktarım bankası <span class="muted">(yalnızca POS)</span></label>
                    <select id="settlement_account_id" name="settlement_account_id">
                        <option value="">—</option>
                        @foreach ($banks as $id => $label)
                            <option value="{{ $id }}" @selected((string) old('settlement_account_id', $account->settlement_account_id) === (string) $id)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-row">
                    <label for="currency">Para birimi</label>
                    <select id="currency" name="currency" @disabled($editing)>
                        <option value="TRY">TRY – Türk Lirası</option>
                    </select>
                </div>
                <div class="form-row checkbox-row">
                    <label>
                        <input type="hidden" name="allow_negative" value="0">
                        <input type="checkbox" name="allow_negative" value="1" @checked(old('allow_negative', $account->allow_negative))>
                        Eksi bakiyeye izin ver <span class="muted">(yalnızca banka / KMH)</span>
                    </label>
                </div>
                @if ($editing)
                    <div class="form-row checkbox-row">
                        <label>
                            <input type="hidden" name="is_active" value="0">
                            <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $account->is_active))>
                            Aktif <span class="muted">(bakiyesi sıfır olmayan hesap pasife alınamaz)</span>
                        </label>
                    </div>
                @endif
            </div>

            @unless ($editing)
                <fieldset class="fieldset">
                    <legend>Açılış / devir bakiyesi (opsiyonel)</legend>
                    <div class="form-grid">
                        <div class="form-row">
                            <label for="opening_amount">Tutar (₺)</label>
                            <input id="opening_amount" name="opening_amount" class="input num-input" value="{{ old('opening_amount') }}" inputmode="decimal" placeholder="0,00">
                        </div>
                        <div class="form-row">
                            <label for="opening_date">Açılış tarihi</label>
                            <input id="opening_date" name="opening_date" type="date" class="input" value="{{ old('opening_date', today()->toDateString()) }}">
                        </div>
                    </div>
                </fieldset>
            @endunless

            <div class="form-actions">
                <a href="{{ $editing ? route('accounts.show', $account) : route('accounts.index') }}" class="btn btn-secondary">Vazgeç</a>
                <button class="btn">{{ $editing ? 'Kaydet' : 'Oluştur' }}</button>
            </div>
        </form>
    </div>
@endsection
