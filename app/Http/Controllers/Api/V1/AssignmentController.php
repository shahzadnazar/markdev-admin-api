<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\SubmitAssignmentRequest;
use App\Http\Resources\AssignmentResource;
use App\Http\Resources\AssignmentSubmissionResource;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssignmentController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Assignment::query()
            ->whereIn('course_id', $this->enrolledCourseIds($request))
            ->with(['course', 'attachments'])
            ->with(['submissions' => fn ($q) => $q->where('user_id', $user->id)]);

        if ($courseId = $request->query('course_id')) {
            $query->where('course_id', $courseId);
        }

        if ($status = $request->query('status')) {
            $this->applyStatusFilter($query, $status, $user->id);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%"));
        }

        $this->applySort($request, $query);

        $assignments = $query->paginate($this->perPage($request))->withQueryString();

        return AssignmentResource::collection($assignments);
    }

    public function show(Request $request, Assignment $assignment): AssignmentResource
    {
        $user = $request->user();

        abort_unless($this->enrolledCourseIds($request)->contains($assignment->course_id), 403);

        $assignment->load([
            'course',
            'attachments',
            'submissions' => fn ($q) => $q->where('user_id', $user->id),
        ]);

        return new AssignmentResource($assignment);
    }

    public function submit(SubmitAssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        $user = $request->user();

        abort_unless($this->enrolledCourseIds($request)->contains($assignment->course_id), 403);

        $submission = AssignmentSubmission::firstOrNew([
            'assignment_id' => $assignment->id,
            'user_id' => $user->id,
        ]);

        abort_if($submission->graded_at !== null, 403, 'This assignment has already been graded.');

        if ($request->filled('content')) {
            $submission->content = $request->string('content')->value();
        }

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $submission->file_path = $file->store('submissions', 'public');
            $submission->file_name = $file->getClientOriginalName();
        }

        $submission->submitted_at = now();
        $submission->is_late = $assignment->due_at !== null && now()->greaterThan($assignment->due_at);
        $submission->save();

        return (new AssignmentSubmissionResource($submission))->response($request)->setStatusCode(201);
    }

    protected function applyStatusFilter(Builder $query, string $status, int $userId): void
    {
        $mine = fn (Builder $q) => $q->where('user_id', $userId);

        match ($status) {
            'graded' => $query->whereHas('submissions', fn (Builder $q) => $mine($q)->whereNotNull('graded_at')),
            'submitted' => $query->whereHas('submissions', fn (Builder $q) => $mine($q)->whereNull('graded_at')),
            'overdue' => $query->whereDoesntHave('submissions', $mine)
                ->whereNotNull('due_at')
                ->where('due_at', '<', now()),
            'pending' => $query->whereDoesntHave('submissions', $mine)
                ->where(fn (Builder $q) => $q->whereNull('due_at')->orWhere('due_at', '>=', now())),
            default => null,
        };
    }

    protected function applySort(Request $request, Builder $query): void
    {
        $sort = (string) $request->query('sort', 'due_at');
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $column = ltrim($sort, '-');

        if (! in_array($column, ['due_at', 'created_at', 'title'], true)) {
            $column = 'due_at';
            $direction = 'asc';
        }

        if ($column === 'due_at') {
            $query->orderByRaw('due_at is null'); // dated assignments first
        }

        $query->orderBy($column, $direction)->orderBy('id');
    }
}
