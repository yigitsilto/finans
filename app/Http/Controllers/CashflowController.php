<?php

namespace App\Http\Controllers;

use App\Services\CashflowService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class CashflowController extends Controller
{
    public const MODES = ['day' => 'Günlük', 'week' => 'Haftalık', 'month' => 'Aylık', 'custom' => 'Özel aralık'];

    private const DEFAULT_BREAKDOWN = ['day' => 'none', 'week' => 'day', 'month' => 'week', 'custom' => 'month'];

    public function index(Request $request, CashflowService $cashflow): View
    {
        $input = $request->validate([
            'mode' => ['nullable', Rule::in(array_keys(self::MODES))],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'from' => ['nullable', 'required_if:mode,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:mode,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'breakdown' => ['nullable', Rule::in(['none', 'day', 'week', 'month'])],
        ], [], ['from' => 'Başlangıç', 'to' => 'Bitiş', 'date' => 'Tarih']);

        $mode = $input['mode'] ?? 'month';
        $anchor = CarbonImmutable::parse($input['date'] ?? today()->toDateString())->startOfDay();

        [$from, $to] = match ($mode) {
            'day' => [$anchor, $anchor],
            'week' => [$anchor->startOfWeek(CarbonImmutable::MONDAY), $anchor->endOfWeek(CarbonImmutable::SUNDAY)->startOfDay()],
            'month' => [$anchor->startOfMonth(), $anchor->endOfMonth()->startOfDay()],
            'custom' => [CarbonImmutable::parse($input['from'])->startOfDay(), CarbonImmutable::parse($input['to'])->startOfDay()],
        };

        $breakdown = $input['breakdown'] ?? self::DEFAULT_BREAKDOWN[$mode];

        try {
            $series = $breakdown === 'none' ? null : $cashflow->series($from, $to, $breakdown);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['breakdown' => $e->getMessage()]);
        }

        return view('cashflow.index', [
            'report' => $cashflow->report($from, $to),
            'series' => $series,
            'mode' => $mode,
            'anchor' => $anchor,
            'breakdown' => $breakdown,
            'prev' => $mode === 'custom' ? null : $this->shift($mode, $anchor, -1),
            'next' => $mode === 'custom' ? null : $this->shift($mode, $anchor, 1),
        ]);
    }

    private function shift(string $mode, CarbonImmutable $anchor, int $direction): string
    {
        $shifted = match ($mode) {
            'day' => $anchor->addDays($direction),
            'week' => $anchor->addWeeks($direction),
            'month' => $anchor->startOfMonth()->addMonthsNoOverflow($direction),
        };

        return $shifted->toDateString();
    }
}
