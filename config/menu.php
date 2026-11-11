<?php

/*
| Ana menü. 'route' => null olan öğeler henüz geliştirilmedi ve pasif görünür.
| 'params' route parametreleri; menü öğesi, URL parametreleri de eşleşirse aktif görünür.
| 'active' yalnızca route adına göre aktiflik deseni (params yoksa kullanılır).
| 'can' verilen öğeler yalnızca o yetkiye sahip kullanıcıya gösterilir.
| Menü gizleme güvenlik değildir; asıl kontrol route'lardaki `can:` middleware'idir.
*/

return [
    [
        'title' => null,
        'items' => [
            ['label' => 'Dashboard', 'route' => 'dashboard'],
        ],
    ],
    [
        'title' => 'Finans',
        'items' => [
            ['label' => 'Hesaplar', 'route' => 'accounts.index', 'active' => 'accounts.*'],
            ['label' => 'Nakit Girişleri', 'route' => 'transactions.index', 'params' => ['type' => 'inflow'], 'active' => 'transactions.*'],
            ['label' => 'Nakit Çıkışları', 'route' => 'transactions.index', 'params' => ['type' => 'outflow'], 'active' => 'transactions.*'],
            ['label' => 'Banka ve Kasa', 'route' => 'accounts.index', 'params' => ['type' => 'available']],
            ['label' => 'POS / Blokeli Hesaplar', 'route' => 'pos.index'],
            ['label' => 'Giderler', 'route' => 'expenses.index', 'active' => 'expenses.*'],
            ['label' => 'Tüm Hareketler', 'route' => 'transactions.index', 'active' => 'transactions.*'],
            ['label' => 'Ortaklar Cari', 'route' => 'partners.index', 'active' => 'partners.*'],
            ['label' => 'Krediler', 'route' => 'loans.index', 'active' => 'loans.*'],
        ],
    ],
    [
        'title' => 'Alacak Yönetimi',
        'items' => [
            ['label' => 'Müşteri Alacakları', 'route' => 'receivables.index', 'active' => 'receivables.*'],
            ['label' => 'Müşteriler', 'route' => 'customers.index', 'active' => 'customers.*'],
            ['label' => 'Yaşlandırma', 'route' => 'aging.index'],
        ],
    ],
    [
        'title' => 'Raporlar',
        'items' => [
            ['label' => 'Nakit Akışı', 'route' => 'cashflow.index'],
            ['label' => 'Finansal Analiz', 'route' => 'analysis.index'],
            ['label' => 'Excel / PDF', 'route' => null, 'sprint' => 9],
        ],
    ],
    [
        'title' => 'Yönetim',
        'items' => [
            ['label' => 'Kullanıcılar', 'route' => 'users.index', 'can' => 'manage-users'],
            ['label' => 'Denetim Kayıtları', 'route' => null, 'sprint' => 9, 'can' => 'view-audit'],
        ],
    ],
];
