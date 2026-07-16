<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\StoreBookmarkRequest;
use App\Http\Resources\BookmarkResource;
use App\Models\Bookmark;
use App\Models\Course;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class BookmarkController extends ApiController
{
    /** @var array<string, class-string> */
    protected const TYPES = [
        'course' => Course::class,
        'lesson' => Lesson::class,
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Bookmark::where('user_id', $request->user()->id)
            ->with(['bookmarkable' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Course::class => ['category'],
                    Lesson::class => ['course'],
                ]);
            }]);

        if ($type = $request->query('type')) {
            abort_unless(isset(self::TYPES[$type]), 422, 'Unknown bookmark type.');
            $query->where('bookmarkable_type', self::TYPES[$type]);
        }

        $bookmarks = $query->latest()->orderByDesc('id')
            ->paginate($this->perPage($request))
            ->withQueryString();

        return BookmarkResource::collection($bookmarks);
    }

    public function store(StoreBookmarkRequest $request): JsonResponse
    {
        $class = self::TYPES[$request->string('type')->value()];
        $id = (int) $request->input('id');

        if (! $class::whereKey($id)->exists()) {
            throw ValidationException::withMessages([
                'id' => ['The selected item does not exist.'],
            ]);
        }

        $bookmark = Bookmark::firstOrCreate([
            'user_id' => $request->user()->id,
            'bookmarkable_type' => $class,
            'bookmarkable_id' => $id,
        ]);

        $bookmark->load(['bookmarkable' => function (MorphTo $morphTo) {
            $morphTo->morphWith([
                Course::class => ['category'],
                Lesson::class => ['course'],
            ]);
        }]);

        return (new BookmarkResource($bookmark))
            ->response($request)
            ->setStatusCode($bookmark->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $type, int $id): Response
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        Bookmark::where('user_id', $request->user()->id)
            ->where('bookmarkable_type', self::TYPES[$type])
            ->where('bookmarkable_id', $id)
            ->delete();

        return response()->noContent();
    }
}
