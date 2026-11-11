@extends('layouts.app')

@php
    use App\Support\Money;
    $t = $transaction;
    $canReverse = ! $t->isReversal() && ! $t->reversal && ! $t->activeSettlement;
@endphp

@section('title', "Hareket #{$t->id}")

@section('content')
    @if ($t->isReversal())
        <div class="alert alert-warning">Bu kayıt <a href="{{ route('transactions.show', $t->reversalOf) }}">#{{ $t->reversal_of_id }}</a> numaralı hareketin ters kaydıdır.</div>
    @elseif ($t->reversal)
        <div class="alert alert-warning">Bu hareket {{ $t->reversal->date->format('d.m.Y') }} tarihinde iptal edildi (ters kayıt <a href="{{ route('transactions.show', $t->reversal) }}">#{{ $t->reversal->id }}</a>).</div>
    @endif

    <div class="detail-grid">
        <div class="card">
            <dl class="details">
                <dt>Tür</dt><dd>{{ $t->type->label() }}</dd>
                <dt>Tarih</dt><dd>{{ $t->date->format('d.m.Y') }}</dd>
                <dt>Tutar</dt><dd class="amount {{ str_starts_with((string) $t->amount, '-') ? 'negative' : '' }}">{{ Money::format($t->amount) }} <small class="muted">{{ $t->currency }}</small></dd>
                @if ($t->category)
                    <dt>Kategori</dt><dd>{{ $t->category->fullName() }} <small class="muted">· {{ $t->category->activity->label() }}</small></dd>
                @endif
                @if ($t->fromAccount)
                    <dt>Kaynak hesap</dt><dd><a href="{{ route('accounts.show', $t->fromAccount) }}">{{ $t->fromAccount->displayName() }}</a></dd>
                @endif
                @if ($t->toAccount)
                    <dt>Hedef hesap</dt><dd><a href="{{ route('accounts.show', $t->toAccount) }}">{{ $t->toAccount->displayName() }}</a></dd>
                @endif
                @if ($t->pos_settlement_date)
                    <dt>Valör tarihi</dt>
                    <dd>{{ $t->pos_settlement_date->format('d.m.Y') }}
                        @if ($t->activeSettlement)
                            · <span class="badge badge-success">Bankaya aktarıldı</span> (<a href="{{ route('transactions.show', $t->activeSettlement) }}">#{{ $t->activeSettlement->id }}</a>)
                        @elseif (! $t->reversal)
                            · <span class="badge badge-warning">Blokeli</span>
                        @endif
                    </dd>
                @endif
                @if ($t->settles)
                    <dt>Aktarılan POS girişi</dt><dd><a href="{{ route('transactions.show', $t->settles) }}">#{{ $t->settles->id }}</a></dd>
                @endif
                <dt>İlgili</dt><dd>{{ $t->counterparty ?? '—' }}</dd>
                <dt>Referans</dt><dd>{{ $t->reference_no ?? '—' }}</dd>
                <dt>Açıklama</dt><dd>{{ $t->description ?? '—' }}</dd>
                <dt>Oluşturan</dt><dd>{{ $t->creator->name }} · {{ $t->created_at->format('d.m.Y H:i') }}</dd>
            </dl>

            @can('write-finance')
                @if (! $t->isReversal() && ! $t->reversal)
                    <div class="form-actions">
                        <a class="btn btn-secondary" href="{{ route('transactions.edit', $t) }}">Düzenle</a>
                    </div>
                @endif
            @endcan
        </div>

        @can('write-finance')
            @if ($canReverse)
                <div class="card">
                    <h2 class="card-title">Hareketi iptal et</h2>
                    <p class="muted">Kayıt silinmez. Aynı tutarda ters kayıt oluşturulur; geçmiş dönem raporları değişmez.</p>
                    <form method="POST" action="{{ route('transactions.reverse', $t) }}" onsubmit="return confirm('Bu hareket iptal edilecek. Emin misiniz?')">
                        @csrf
                        <div class="form-row">
                            <label for="rev-date">İptal tarihi</label>
                            <input id="rev-date" type="date" name="date" class="input" value="{{ old('date', today()->toDateString()) }}"
                                   min="{{ $t->date->toDateString() }}" max="{{ today()->toDateString() }}" required>
                        </div>
                        <div class="form-row">
                            <label for="reason">İptal nedeni</label>
                            <input id="reason" name="reason" class="input" value="{{ old('reason') }}" required minlength="3" maxlength="500">
                        </div>
                        <button class="btn btn-danger">Ters kayıt oluştur</button>
                    </form>
                </div>
            @elseif ($t->activeSettlement)
                <div class="card">
                    <p class="muted">Bu POS girişi bankaya aktarıldığı için doğrudan iptal edilemez. Önce aktarım transferini (#{{ $t->activeSettlement->id }}) iptal edin.</p>
                </div>
            @endif
        @endcan
    </div>

    @if ($history->isNotEmpty())
        <h2 class="section-title">Değişiklik geçmişi</h2>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Tarih</th><th>Kullanıcı</th><th>İşlem</th><th>Değişiklik</th></tr></thead>
                <tbody>
                @foreach ($history as $log)
                    <tr>
                        <td>{{ $log->created_at->format('d.m.Y H:i') }}</td>
                        <td>{{ $log->username }}</td>
                        <td>{{ $log->action }}</td>
                        <td class="mono small">
                            @if ($log->action === 'updated')
                                @foreach ($log->new_values ?? [] as $field => $value)
                                    {{ $field }}: {{ json_encode($log->old_values[$field] ?? null, JSON_UNESCAPED_UNICODE) }} → {{ json_encode($value, JSON_UNESCAPED_UNICODE) }}<br>
                                @endforeach
                            @elseif ($log->action === 'reversed')
                                {{ $log->new_values['reason'] ?? '' }}
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
