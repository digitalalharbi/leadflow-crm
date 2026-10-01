<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** In-app notifications. The SPA polls this; no WebSockets in the MVP. */
class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()->limit(30)->get();

        return ApiResponse::success([
            'notifications' => $notifications->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'data' => $n->data,
                'read_at' => $n->read_at?->toIso8601String(),
                'created_at' => $n->created_at->toIso8601String(),
            ]),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    public function markRead(Request $request, string $id)
    {
        $notification = $request->user()->notifications()->where('id', $id)->firstOrFail();
        $notification->markAsRead();

        return ApiResponse::success(null);
    }

    public function markAllRead(Request $request)
    {
        // One UPDATE, not one per notification.
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(null);
    }
}
