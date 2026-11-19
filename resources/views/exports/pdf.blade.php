<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>{{ $report->title }}</title>
    <style>
        @page { margin: 28px 28px 40px; }
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 8.5px; color: #1c2430; }
        header { border-bottom: 2px solid #12355b; padding-bottom: 6px; margin-bottom: 10px; }
        .company { font-size: 13px; font-weight: bold; color: #12355b; }
        h1 { font-size: 12px; margin: 2px 0; }
        .meta { color: #6b7685; }
        .filters { margin: 6px 0; }
        .filters span { margin-right: 10px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th { background: #12355b; color: #fff; text-align: left; padding: 3px 4px; font-weight: bold; }
        td { padding: 2.5px 4px; border-bottom: 0.5px solid #dfe3ea; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .neg { color: #b42318; }
        tr.total td { font-weight: bold; background: #eef2f7; border-top: 1px solid #12355b; }
        h2 { font-size: 10px; margin: 10px 0 4px; color: #12355b; }
        h2 { page-break-after: avoid; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        .summary td { border: none; padding: 1.5px 4px; }
        .summary td.label { width: 55%; }
        .summary td.value { font-weight: bold; }
        .note { color: #6b7685; font-style: italic; margin: 2px 0; }
        .warn { color: #9a6700; font-weight: bold; margin: 4px 0; }
        footer { position: fixed; bottom: -26px; left: 0; right: 0; color: #6b7685; font-size: 7.5px; }
    </style>
</head>
<body>
@php
    $fmt = function ($value, string $type) {
        if ($value === null || $value === '') return '';
        return match ($type) {
            'money' => \App\Support\Money::format((string) $value),
            'percent' => '%'.str_replace('.', ',', (string) $value),
            'date' => \Carbon\Carbon::parse($value)->format('d.m.Y'),
            default => (string) $value,
        };
    };
    $isNeg = fn ($value, string $type) => in_array($type, ['money', 'percent'], true) && str_starts_with((string) $value, '-');
@endphp

<footer>Zafir Sigorta Finans · {{ $report->title }} · Oluşturulma {{ now()->format('d.m.Y H:i') }}</footer>

<header>
    <div class="company">Zafir Sigorta Aracılık Hizmetleri</div>
    <h1>{{ $report->title }}</h1>
    <div class="meta">Dönem: {{ $report->period }} · Oluşturulma: {{ now()->format('d.m.Y H:i') }} · {{ $generatedBy }}</div>
    @if ($report->filters)
        <div class="filters meta">
            @foreach ($report->filters as $label => $value) <span><b>{{ $label }}:</b> {{ $value }}</span> @endforeach
        </div>
    @endif
</header>

@if ($truncated)
    <p class="warn">Bu PDF ilk {{ number_format($maxRows, 0, ',', '.') }} satırı içerir. Tüm veriler için Excel çıktısını kullanın.</p>
@endif

@if ($report->summary)
    <table class="summary">
        @foreach ($report->summary as $item)
            <tr><td class="label">{{ $item['label'] }}</td><td class="value num {{ $isNeg($item['value'], $item['type']) ? 'neg' : '' }}">{{ $fmt($item['value'], $item['type']) }}</td></tr>
        @endforeach
    </table>
@endif

@foreach ($report->sections as $section)
    @if ($section['title']) <h2>{{ $section['title'] }}</h2> @endif
    <table>
        <thead>
        <tr>
            @foreach ($section['columns'] as $col)
                <th class="{{ in_array($col['type'], ['money', 'percent', 'int'], true) ? 'num' : '' }}">{{ $col['label'] }}</th>
            @endforeach
        </tr>
        </thead>
        <tbody>
        @forelse ($section['rows'] as $row)
            <tr>
                @foreach ($section['columns'] as $i => $col)
                    <td class="{{ in_array($col['type'], ['money', 'percent', 'int'], true) ? 'num' : '' }} {{ $isNeg($row[$i] ?? null, $col['type']) ? 'neg' : '' }}">{{ $fmt($row[$i] ?? null, $col['type']) }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($section['columns']) }}" class="note">Kayıt yok.</td></tr>
        @endforelse
        @if ($section['totals'] !== null)
            <tr class="total">
                @foreach ($section['columns'] as $i => $col)
                    <td class="{{ $i > 0 && in_array($col['type'], ['money', 'percent', 'int'], true) ? 'num' : '' }}">{{ $fmt($section['totals'][$i] ?? null, $i === 0 ? 'text' : $col['type']) }}</td>
                @endforeach
            </tr>
        @endif
        </tbody>
    </table>
@endforeach

@foreach ($report->notes as $note)
    <p class="note">{{ $note }}</p>
@endforeach
</body>
</html>
