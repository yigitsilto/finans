@extends('layouts.app')

@php($editing = $customer->exists)

@section('title', $editing ? 'Müşteriyi düzenle' : 'Yeni müşteri')

@section('content')
    <div class="card form-card">
        <form method="POST" action="{{ $editing ? route('customers.update', $customer) : route('customers.store') }}">
            @csrf
            @if ($editing) @method('PUT') @endif
            <input type="hidden" name="return_to" value="{{ request('return_to') }}">
            <div class="form-grid">
                <div class="form-row">
                    <label for="type">Müşteri türü</label>
                    <select id="type" name="type" required>
                        <option value="individual" @selected(old('type', $customer->type) === 'individual')>Bireysel</option>
                        <option value="corporate" @selected(old('type', $customer->type) === 'corporate')>Kurumsal</option>
                    </select>
                </div>
                <div class="form-row">
                    <label for="name">Ad soyad / unvan</label>
                    <input id="name" name="name" class="input" value="{{ old('name', $customer->name) }}" required maxlength="150">
                </div>
                <div class="form-row">
                    <label for="tax_number">TCKN / VKN <span class="muted">(opsiyonel, şifreli saklanır)</span></label>
                    <input id="tax_number" name="tax_number" class="input mono" inputmode="numeric" maxlength="14" autocomplete="off"
                           value="{{ old('tax_number', $customer->tax_number) }}">
                </div>
                <div class="form-row">
                    <label for="phone">Telefon</label>
                    <input id="phone" name="phone" class="input" value="{{ old('phone', $customer->phone) }}" maxlength="30">
                </div>
                <div class="form-row">
                    <label for="email">E-posta</label>
                    <input id="email" name="email" type="email" class="input" value="{{ old('email', $customer->email) }}" maxlength="150">
                </div>
            </div>
            <div class="form-row">
                <label for="notes">Notlar</label>
                <textarea id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes', $customer->notes) }}</textarea>
            </div>
            @if ($editing)
                <div class="form-row checkbox-row">
                    <label>
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $customer->is_active))> Aktif
                    </label>
                </div>
            @endif
            <div class="form-actions">
                <a href="{{ $editing ? route('customers.show', $customer) : route('customers.index') }}" class="btn btn-secondary">Vazgeç</a>
                <button class="btn">{{ $editing ? 'Kaydet' : 'Oluştur' }}</button>
            </div>
        </form>
    </div>
@endsection
