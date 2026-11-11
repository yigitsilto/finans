<?php

/*
| Finansal analiz eşikleri. İş kararıdır; değiştirmek için .env yeterlidir.
*/

return [
    // Likidite riski: bugünden itibaren kaç günlük zorunlu ödemeye bakılır
    'liquidity_horizon_days' => (int) env('FINANCE_LIQUIDITY_HORIZON_DAYS', 30),

    // Zorunlu ödemeler kullanılabilir nakdin bu yüzdesini aşarsa "dikkat" (100'ü aşarsa "kritik")
    'liquidity_warning_percent' => (int) env('FINANCE_LIQUIDITY_WARNING_PERCENT', 80),

    // Sabit gider oranı bu yüzdeyi aşarsa "dikkat" (bilgi amaçlı eşik)
    'fixed_expense_warning_percent' => (int) env('FINANCE_FIXED_EXPENSE_WARNING_PERCENT', 70),
];
