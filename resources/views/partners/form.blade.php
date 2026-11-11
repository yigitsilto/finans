@extends('layouts.app')

@php($editing = $partner->exists)

@section('title', $editing ? 'Ortağı düzenle' : 'Yeni ortak')

@section('content')
    <div class="card form-card">
        <form method="POST" action="{{ $editing ? route('partners.update', $partner) : route('partners.store') }}">
            @csrf
            @if ($editing) @method('PUT') @endif
            <div class="form-grid">
                <div class="form-row">
                    <label for="name">Ad soyad</label>
                    <input id="name" name="name" class="input" value="{{ old('name', $partner->name) }}" required maxlength="120">
                </div>
                <div class="form-row">
                    <label for="share_percent">Ortaklık payı (%) <span class="muted">(opsiyonel)</span></label>
                    <input id="share_percent" name="share_percent" class="input num-input" inputmode="decimal"
                           value="{{ old('share_percent', $partner->share_percent ? str_replace('.', ',', $partner->share_percent) : '') }}">
                </div>
            </div>
            <div class="form-row">
                <label for="notes">Notlar</label>
                <textarea id="notes" name="notes" rows="2" maxlength="1000">{{ old('notes', $partner->notes) }}</textarea>
            </div>
            @if ($editing)
                <div class="form-row checkbox-row">
                    <label>
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $partner->is_active))> Aktif
                    </label>
                </div>
            @endif
            <div class="form-actions">
                <a href="{{ $editing ? route('partners.show', $partner) : route('partners.index') }}" class="btn btn-secondary">Vazgeç</a>
                <button class="btn">{{ $editing ? 'Kaydet' : 'Oluştur' }}</button>
            </div>
        </form>
    </div>
@endsection
