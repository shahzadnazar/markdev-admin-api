<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\LeaveApplicationResource;
use App\Models\LeaveApplication;
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

        return LeaveApplicationResource::collection($leaves);
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

        $leave = LeaveApplication::create([
            'user_id' => $request->user()->id,
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'reason' => $data['reason'],
        ]);

        return (new LeaveApplicationResource($leave->load('decisions')))
            ->response()
            ->setStatusCode(201);
    }
}
