@php($levels = \App\Services\FinancialAnalysisService::LEVELS)
<ul class="alert-list">
    @forelse ($alerts as $alert)
        <li class="alert-item level-{{ $alert['level'] }}">
            <span class="badge alert-level">{{ $levels[$alert['level']] }}</span>
            <div>
                <strong>{{ $alert['title'] }}</strong>
                <span>{{ $alert['message'] }}</span>
            </div>
            @if ($alert['url'])
                <a href="{{ $alert['url'] }}" class="alert-link">İncele ›</a>
            @endif
        </li>
    @empty
        <li class="alert-item level-ok"><span class="badge badge-success">Tamam</span><div><strong>Uyarı yok</strong></div></li>
    @endforelse
</ul>
