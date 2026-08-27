<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\AnnouncementResource;
use App\Models\Announcement;
use App\Models\AnnouncementRead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AnnouncementController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Announcement::published()
            ->where(fn (Builder $q) => $q->whereNull('course_id')->orWhereIn('course_id', $this->enrolledCourseIds($request)))
            ->with(['author', 'course']);

        if ($courseId = $request->query('course_id')) {
            $query->where('course_id', $courseId);
        }

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn (Builder $q) => $q
                ->where('title', 'like', "%{$search}%")
                ->orWhere('body', 'like', "%{$search}%"));
        }

        $announcements = $query->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        $readIds = AnnouncementRead::where('user_id', $user->id)
            ->whereIn('announcement_id', collect($announcements->items())->pluck('id'))
            ->pluck('announcement_id')
            ->flip();

        collect($announcements->items())->each(
            fn (Announcement $announcement) => $announcement->setAttribute('is_read', $readIds->has($announcement->id)),
        );

        return AnnouncementResource::collection($announcements);
    }

    /**
     * Announcements still inside their live window, split by how they surface.
     *
     * The portal polls this for the top-bar ticker and the popup, so it stays
     * deliberately small — no pagination, no body search, just what has to be
     * shown right now.
     */
    public function live(Request $request): JsonResponse
    {
        $announcements = Announcement::live()
            ->where(fn (Builder $q) => $q->whereNull('course_id')->orWhereIn('course_id', $this->enrolledCourseIds($request)))
            ->with(['author', 'course'])
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->limit(20)
            ->get();

        $payload = fn (Announcement $a) => [
            'id' => $a->id,
            'title' => $a->title,
            'body' => $a->body,
            'author' => ['id' => $a->author?->id, 'name' => $a->author?->name],
            'course' => $a->course ? ['id' => $a->course->id, 'title' => $a->course->title] : null,
            'published_at' => $a->published_at?->toISOString(),
            'live_until' => $a->liveUntil()?->toISOString(),
        ];

        $grouped = $announcements->groupBy(fn (Announcement $a) => $a->display());

        return response()->json(['data' => [
            'ticker' => $grouped->get('ticker', collect())->map($payload)->values(),
            'popup' => $grouped->get('popup', collect())->map($payload)->values(),
        ]]);
    }

    public function show(Request $request, Announcement $announcement): AnnouncementResource
    {
        $this->authorizeVisibility($request, $announcement);

        $announcement->load(['author', 'course']);
        $announcement->setAttribute('is_read', AnnouncementRead::where('user_id', $request->user()->id)
            ->where('announcement_id', $announcement->id)
            ->exists());

        return new AnnouncementResource($announcement);
    }

    public function read(Request $request, Announcement $announcement): JsonResponse
    {
        $this->authorizeVisibility($request, $announcement);

        AnnouncementRead::firstOrCreate(
            ['announcement_id' => $announcement->id, 'user_id' => $request->user()->id],
            ['read_at' => now()],
        );

        return response()->json(['data' => ['message' => 'Announcement marked as read.']]);
    }

    protected function authorizeVisibility(Request $request, Announcement $announcement): void
    {
        abort_if($announcement->published_at === null || $announcement->published_at->isFuture(), 404);

        if ($announcement->course_id !== null) {
            abort_unless($this->enrolledCourseIds($request)->contains($announcement->course_id), 403);
        }
    }
}
