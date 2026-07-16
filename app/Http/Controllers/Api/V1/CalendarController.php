<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\CalendarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends ApiController
{
    public function __invoke(Request $request, CalendarService $calendar): JsonResponse
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->query('from'))->startOfDay() : now()->startOfDay();
        $to = $request->filled('to') ? Carbon::parse($request->query('to'))->endOfDay() : $from->copy()->addDays(30)->endOfDay();

        return response()->json([
            'data' => $calendar->eventsBetween($request->user(), $from, $to),
        ]);
    }
}
