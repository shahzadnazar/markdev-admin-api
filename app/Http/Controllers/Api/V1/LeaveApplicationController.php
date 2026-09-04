<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\LeaveApplicationResource;
use App\Models\LeaveApplication;
use App\Support\LeaveAllowance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class LeaveApplicationController extends ApiController
{
    /** The student's own leave applications, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $leaves = LeaveApplication::where('user_id', $request->user()->id)
            ->with('decisions')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return LeaveApplicationResource::collection($leaves)->additional([
            // The portal renders the counter and the over-limit warning from
            // these and computes none of it: the allowance is an admin setting
            // and a client-side copy would go stale the moment it changed.
            'balance' => LeaveAllowance::balance($request->user()->id, Carbon::now()),
            'balances' => LeaveAllowance::upcomingBalances($request->user()->id),
        ]);
    }

    /** Apply for leave — one non-rejected application per date range. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_date' => ['required', 'date', 'after_or_equal:today'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date', 'before_or_equal:'.today()->addDays(60)->toDateString()],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $overlaps = LeaveApplication::where('user_id', $request->user()->id)
            ->where('status', '!=', 'rejected')
            ->whereDate('from_date', '<=', $data['to_date'])
            ->whereDate('to_date', '>=', $data['from_date'])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages([
                'from_date' => 'You already have a pending or approved leave that overlaps these dates.',
            ]);
        }

        // The same rule the portal disables its submit button on. A disabled
        // button is a courtesy; this is the rule.
        $shortfall = LeaveAllowance::shortfall(
            $request->user()->id,
            Carbon::parse($data['from_date']),
            Carbon::parse($data['to_date']),
        );

        if ($shortfall !== null) {
            throw ValidationException::withMessages([
                'from_date' => LeaveAllowance::shortfallMessage($shortfall),
            ]);
        }

        $leave = DB::transaction(function () use ($request, $data) {
            $leave = LeaveApplication::create([
                'user_id' => $request->user()->id,
                'from_date' => $data['from_date'],
                'to_date' => $data['to_date'],
                'reason' => $data['reason'],
            ]);

            // Opened here, not at review: a day only counts against the month
            // once a row exists, and the reservation has to start the moment
            // the student asks or two requests could each look affordable.
            $leave->openDecisions();

            return $leave;
        });

        return (new LeaveApplicationResource($leave->load('decisions')))
            ->response()
            ->setStatusCode(201);
    }
}
