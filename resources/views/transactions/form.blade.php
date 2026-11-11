@extends('layouts.app')

@php
    use App\Enums\TransactionType;
    use App\Support\Money;
    $editing = $transaction->exists;
    $titles = ['inflow' => 'Nakit girişi', 'outflow' => 'Nakit çıkışı', 'transfer' => 'Hesaplar arası transfer', 'opening' => 'Açılış bakiyesi'];
    $needsFrom = in_array($type, [TransactionType::Outflow, TransactionType::Transfer], true);
    $needsTo = $type !== TransactionType::Outflow;
    $hasCategory = $categories->isNotEmpty();
@endphp

@section('title', ($editing ? "#{$transaction->id} düzenle · " : 'Yeni ').mb_strtolower($titles[$type->value]))

@section('content')
    <div class="card form-card">
        @if ($editing)
            <div class="alert alert-warning">
                Tutar, tarih ve hesap bilgileri değiştirilemez. Bunlar hatalıysa hareketi iptal edip (ters kayıt) yeniden girin.
            </div>
        @endif

        <form method="POST" action="{{ $editing ? route('transactions.update', $transaction) : route('transactions.store') }}">
            @csrf
            @if ($editing) @method('PATCH') @endif
            <input type="hidden" name="type" value="{{ $type->value }}">

            <div class="form-grid">
                <div class="form-row">
                    <label for="date">İşlem tarihi</label>
                    <input id="date" name="date" type="date" class="input" value="{{ old('date', $transaction->date?->toDateString()) }}"
                           max="{{ today()->toDateString() }}" required @disabled($editing)>
                </div>
                <div class="form-row">
                    <label for="amount">Tutar (₺)</label>
                    <input id="amount" name="amount" class="input num-input" inputmode="decimal" placeholder="0,00" required
                           value="{{ old('amount', $editing ? Money::format($transaction->amount, false) : '') }}" @disabled($editing)>
                    <small class="muted">Örnek: 27.000,50</small>
                </div>

                @if ($needsFrom)
                    <div class="form-row">
                        <label for="from_account_id">Kaynak hesap</label>
                        <select id="from_account_id" name="from_account_id" required @disabled($editing)>
                            <option value="">Seçin</option>
                            @foreach ($fromAccounts as $id => $label)
                                <option value="{{ $id }}" @selected((string) old('from_account_id', $transaction->from_account_id) === (string) $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($needsTo)
                    <div class="form-row">
                        <label for="to_account_id">{{ $type === TransactionType::Inflow ? 'Giriş yapılan hesap' : 'Hedef hesap' }}</label>
                        <select id="to_account_id" name="to_account_id" required @disabled($editing)>
                            <option value="">Seçin</option>
                            @foreach ($toAccounts as $id => $label)
                                <option value="{{ $id }}" @selected((string) old('to_account_id', $transaction->to_account_id) === (string) $id)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($type === TransactionType::Inflow)
                    <div class="form-row">
                        <label for="pos_settlement_date">Valör tarihi <span class="muted">(yalnızca POS tahsilatı)</span></label>
                        <input id="pos_settlement_date" name="pos_settlement_date" type="date" class="input"
                               value="{{ old('pos_settlement_date', $transaction->pos_settlement_date?->toDateString()) }}" @disabled($editing)>
                        <small class="muted">POS tutarı bu tarihte bağlı banka hesabına aktarılır; o zamana kadar blokeli sayılır.</small>
                    </div>
                @endif

                @if ($hasCategory)
                    <div class="form-row">
                        <label for="category_id">Kategori</label>
                        <select id="category_id" name="category_id" required>
                            <option value="">Seçin</option>
                            @foreach ($categories as $group => $options)
                                <optgroup label="{{ $group }}">
                                    @foreach ($options as $id => $name)
                                        <option value="{{ $id }}" @selected((string) old('category_id', $transaction->category_id) === (string) $id)>{{ $name }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                @endif

                @if ($type !== TransactionType::Opening)
                    <div class="form-row">
                        <label for="counterparty">İlgili müşteri / sigorta şirketi / ortak</label>
                        <input id="counterparty" name="counterparty" class="input" maxlength="150" value="{{ old('counterparty', $transaction->counterparty) }}">
                    </div>
                @endif
                <div class="form-row">
                    <label for="reference_no">Belge / referans no</label>
                    <input id="reference_no" name="reference_no" class="input" maxlength="100" value="{{ old('reference_no', $transaction->reference_no) }}">
                </div>
            </div>

            <div class="form-row">
                <label for="description">Açıklama</label>
                <textarea id="description" name="description" rows="2" maxlength="1000">{{ old('description', $transaction->description) }}</textarea>
            </div>

            <div class="form-actions">
                <a href="{{ $editing ? route('transactions.show', $transaction) : route('transactions.index', ['type' => $type->value]) }}" class="btn btn-secondary">Vazgeç</a>
                <button class="btn">{{ $editing ? 'Kaydet' : 'Kaydet' }}</button>
            </div>
        </form>
    </div>
@endsection
