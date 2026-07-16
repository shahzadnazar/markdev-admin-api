<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\NotificationResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class NotificationController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $request->user()->notifications();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return NotificationResource::collection(
            $query->paginate($this->perPage($request))->withQueryString(),
        );
    }

    public function counts(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['unread' => $request->user()->unreadNotifications()->count()],
        ]);
    }

    public function read(Request $request, string $id): NotificationResource
    {
        $notification = $request->user()->notifications()->findOrFail($id);

        $notification->markAsRead();

        return new NotificationResource($notification);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['data' => ['message' => 'All notifications marked as read.']]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $request->user()->notifications()->findOrFail($id)->delete();

        return response()->noContent();
    }
}
