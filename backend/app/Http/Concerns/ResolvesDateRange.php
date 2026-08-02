<?php

namespace App\Http\Concerns;

use Carbon\Carbon;
use Illuminate\Http\Request;

trait ResolvesDateRange
{
    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function resolveDateRange(Request $request): array
    {
        $validated = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $start = isset($validated['start_date']) ? Carbon::parse($validated['start_date']) : now()->startOfWeek();
        $end = isset($validated['end_date']) ? Carbon::parse($validated['end_date']) : now()->endOfWeek();

        return [$start, $end];
    }
}
