<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Notifications\MarkAllNotificationsReadRequest;
use App\Http\Requests\Api\V1\Admin\Notifications\NotificationIndexRequest;
use App\Http\Resources\Api\V1\Admin\InternalNotificationResource;
use App\Models\User;
use App\Services\InternalNotificationService;
use Illuminate\Http\JsonResponse;

class InternalNotificationController extends Controller
{
    public function __construct(private readonly InternalNotificationService $notifications) {}

    public function index(NotificationIndexRequest $request)
    {
        $validated = $request->validated();
        /** @var User $user */
        $user = $request->user();

        $query = $user->internalNotifications()->latest();
        if (($validated['filter'] ?? 'all') === 'unread') {
            $query->whereNull('read_at');
        }
        if (isset($validated['context'])) {
            $query->where('context', $validated['context']);
        }

        return InternalNotificationResource::collection($query->paginate($validated['per_page'] ?? 20)->withQueryString());
    }

    public function summary(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        return response()->json(['data' => $this->notifications->summary($user)]);
    }

    public function markRead(string $publicId): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();

        return response()->json(['data' => (new InternalNotificationResource($this->notifications->markAsRead($user, $publicId)))->resolve()]);
    }

    public function markAllRead(MarkAllNotificationsReadRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $context = $request->validated('context');

        return response()->json(['data' => [
            'marked_count' => $this->notifications->markAllAsRead($user, $context),
            ...$this->notifications->summary($user),
        ]]);
    }
}
