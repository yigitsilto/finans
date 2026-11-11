@extends('layouts.app')

@php use App\Http\Controllers\AuditLogController; @endphp

@section('title', 'Denetim Kayıtları')

@section('content')
    <div class="toolbar">
        <form method="GET" class="filters">
            <select name="user_id" aria-label="Kullanıcı">
                <option value="">Tüm kullanıcılar</option>
                @foreach ($users as $id => $username)
                    <option value="{{ $id }}" @selected((string) ($filters['user_id'] ?? '') === (string) $id)>{{ $username }}</option>
                @endforeach
            </select>
            <select name="action" aria-label="İşlem">
                <option value="">Tüm işlemler</option>
                @foreach (AuditLogController::ACTIONS as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['action'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <select name="type" aria-label="Kaynak türü">
                <option value="">Tüm kaynaklar</option>
                @foreach ($types as $type)
                    <option value="{{ $type }}" @selected(($filters['type'] ?? '') === $type)>{{ $type }}</option>
                @endforeach
            </select>
            <input type="date" name="from" class="input" value="{{ $filters['from'] ?? '' }}" aria-label="Başlangıç">
            <input type="date" name="to" class="input" value="{{ $filters['to'] ?? '' }}" aria-label="Bitiş">
            <input type="search" name="q" class="input" placeholder="Endpoint, kullanıcı adı, IP" value="{{ $filters['q'] ?? '' }}">
            <button class="btn btn-secondary">Filtrele</button>
        </form>
        <div class="actions">
            <a class="btn btn-secondary" href="{{ route('exports.download', ['report' => 'audit', 'format' => 'xlsx'] + $filters) }}">Excel</a>
            <a class="btn btn-secondary" href="{{ route('exports.download', ['report' => 'audit', 'format' => 'pdf'] + $filters) }}">PDF</a>
        </div>
    </div>

    <div class="table-wrap">
        <table class="table compact">
            <thead>
            <tr><th>#</th><th>Tarih</th><th>Kullanıcı</th><th>İşlem</th><th>Kaynak</th><th>Endpoint</th><th class="num">HTTP</th><th>IP</th></tr>
            </thead>
            <tbody>
            @forelse ($logs as $log)
                <tr class="{{ $log->action === 'login_failed' ? 'row-warning' : '' }}">
                    <td><a href="{{ route('audit.show', $log) }}">{{ $log->id }}</a></td>
                    <td class="nowrap">{{ $log->created_at->format('d.m.Y H:i:s') }}</td>
                    <td>{{ $log->username ?? '—' }}</td>
                    <td>{{ AuditLogController::ACTIONS[$log->action] ?? $log->action }}</td>
                    <td>{{ $log->auditable_type ? $log->auditable_type.' #'.$log->auditable_id : '—' }}</td>
                    <td class="mono small">{{ $log->method }} {{ $log->endpoint }}</td>
                    <td class="num">{{ $log->http_status ?? '—' }}</td>
                    <td class="mono small">{{ $log->ip_address }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="muted">Kayıt bulunamadı.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    {{ $logs->links() }}
    <p class="muted small">Denetim kayıtları değiştirilemez ve silinemez. Parola, token gibi gizli bilgiler kayda yazılmaz; IBAN maskelenir.</p>
@endsection
