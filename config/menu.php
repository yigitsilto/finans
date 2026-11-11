<?php

/*
| Ana menü. 'route' => null olan öğeler henüz geliştirilmedi ve pasif görünür.
| 'roles' Sprint 2'de menü filtrelemesi için kullanılacak (backend yetkisi ayrıca kontrol edilir).
*/

return [
    [
        'title' => null,
        'items' => [
            ['label' => 'Dashboard', 'route' => 'dashboard', 'roles' => ['admin', 'finance', 'viewer']],
        ],
    ],
    [
        'title' => 'Finans',
        'items' => [
            ['label' => 'Hesaplar', 'route' => null, 'sprint' => 3],
            ['label' => 'Nakit Girişleri', 'route' => null, 'sprint' => 3],
            ['label' => 'Nakit Çıkışları', 'route' => null, 'sprint' => 3],
            ['label' => 'Banka ve Kasa', 'route' => null, 'sprint' => 3],
            ['label' => 'POS / Blokeli Hesaplar', 'route' => null, 'sprint' => 3],
            ['label' => 'Ortaklar Cari', 'route' => null, 'sprint' => 4],
            ['label' => 'Krediler', 'route' => null, 'sprint' => 4],
        ],
    ],
    [
        'title' => 'Alacak Yönetimi',
        'items' => [
            ['label' => 'Müşteri Alacakları', 'route' => null, 'sprint' => 5],
            ['label' => 'Yaşlandırma', 'route' => null, 'sprint' => 6],
        ],
    ],
    [
        'title' => 'Raporlar',
        'items' => [
            ['label' => 'Nakit Akışı', 'route' => null, 'sprint' => 7],
            ['label' => 'Finansal Analiz', 'route' => null, 'sprint' => 8],
            ['label' => 'Excel / PDF', 'route' => null, 'sprint' => 9],
        ],
    ],
    [
        'title' => 'Yönetim',
        'items' => [
            ['label' => 'Kullanıcılar', 'route' => null, 'sprint' => 2, 'roles' => ['admin']],
            ['label' => 'Denetim Kayıtları', 'route' => null, 'sprint' => 9, 'roles' => ['admin']],
        ],
    ],
];
