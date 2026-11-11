@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="empty-state">
        <h2>Henüz finansal veri yok</h2>
        <p>
            Dashboard kartları ve grafikler yalnızca gerçek kayıtlardan hesaplanır.
            Hesap ve hareket kayıtları Sprint 3 ile, dashboard hesaplamaları Sprint 8 ile gelecek.
        </p>
        <p class="muted">Sistem durumu: <a href="{{ route('health') }}">/health</a></p>
    </div>
@endsection
