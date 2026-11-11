<?php

namespace Tests\Unit;

use App\Services\AgingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AgingBucketTest extends TestCase
{
    /** PRD §26 Gün 6 sınır değerleri + iki uç. */
    public static function boundaries(): array
    {
        return [
            'vade 30 gün sonra' => [-30, 'not_due'],
            'vade yarın (-1)' => [-1, 'not_due'],
            'vade bugün (0)' => [0, 'not_due'],
            '1 gün' => [1, 'd0_30'],
            '30 gün' => [30, 'd0_30'],
            '31 gün' => [31, 'd31_60'],
            '60 gün' => [60, 'd31_60'],
            '61 gün' => [61, 'd61_90'],
            '90 gün' => [90, 'd61_90'],
            '91 gün' => [91, 'd90_plus'],
            '3 yıl' => [1095, 'd90_plus'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_bucket_boundaries(int $days, string $expected): void
    {
        $this->assertSame($expected, AgingService::bucket($days));
    }

    public function test_there_are_exactly_five_buckets_in_risk_order(): void
    {
        $this->assertSame(['not_due', 'd0_30', 'd31_60', 'd61_90', 'd90_plus'], array_keys(AgingService::BUCKETS));
    }
}
