@extends('layouts.app')

@php use App\Http\Controllers\AuditLogController; @endphp

@section('title', "Denetim kaydı #{$log->id}")

@section('content')
    <div class="card">
        <dl class="details">
            <dt>Tarih</dt><dd>{{ $log->created_at->format('d.m.Y H:i:s') }}</dd>
            <dt>Kullanıcı</dt><dd>{{ $log->username ?? '—' }} @if ($log->user) <small class="muted">({{ $log->user->name }}, {{ $log->user->role->label() }})</small> @endif</dd>
            <dt>İşlem</dt><dd>{{ AuditLogController::ACTIONS[$log->action] ?? $log->action }}</dd>
            <dt>Kaynak</dt><dd>{{ $log->auditable_type ? $log->auditable_type.' #'.$log->auditable_id : '—' }}</dd>
            <dt>Endpoint</dt><dd class="mono">{{ $log->method }} {{ $log->endpoint }}</dd>
            <dt>HTTP sonucu</dt><dd>{{ $log->http_status ?? '—' }}</dd>
            <dt>IP / tarayıcı</dt><dd class="small">{{ $log->ip_address }} · {{ $log->user_agent }}</dd>
        </dl>
    </div>

    <div class="cf-columns section-gap">
        <div class="card">
            <h2 class="card-title">Eski değerler</h2>
            <pre class="json">{{ $log->old_values ? json_encode($log->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—' }}</pre>
        </div>
        <div class="card">
            <h2 class="card-title">Yeni değerler</h2>
            <pre class="json">{{ $log->new_values ? json_encode($log->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '—' }}</pre>
        </div>
    </div>
    <p class="section-gap"><a href="{{ url()->previous() === url()->current() ? route('audit.index') : url()->previous() }}">‹ Geri</a></p>
@endsection
