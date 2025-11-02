<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Helpers\PaginationHelper;

class NotificationController extends Controller
{
    /**
     * Get all notifications for the authenticated user
     */
    public function index(Request $request)
    {
        $query = DB::table('user_notifications')
            ->where('user_id', $request->user()->id)
            ->orderBy('created_at', 'desc');

        $notifications = PaginationHelper::paginate($query, $request);

        return response()->json($notifications);
    }

    /**
     * Get unread notification count
     */
    public function unreadCount(Request $request)
    {
        $count = DB::table('user_notifications')
            ->where('user_id', $request->user()->id)
            ->where('read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * Mark a notification as read
     */
    public function markAsRead(Request $request, $id)
    {
        $notification = DB::table('user_notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification not found',
            ], 404);
        }

        DB::table('user_notifications')
            ->where('id', $id)
            ->update([
                'read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'Notification marked as read',
        ]);
    }

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request)
    {
        DB::table('user_notifications')
            ->where('user_id', $request->user()->id)
            ->where('read', false)
            ->update([
                'read' => true,
                'read_at' => now(),
                'updated_at' => now(),
            ]);

        return response()->json([
            'message' => 'All notifications marked as read',
        ]);
    }

    /**
     * Delete a notification
     */
    public function destroy(Request $request, $id)
    {
        $notification = DB::table('user_notifications')
            ->where('id', $id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$notification) {
            return response()->json([
                'message' => 'Notification not found',
            ], 404);
        }

        DB::table('user_notifications')
            ->where('id', $id)
            ->delete();

        return response()->json([
            'message' => 'Notification deleted',
        ]);
    }

    /**
     * Delete all read notifications
     */
    public function deleteAllRead(Request $request)
    {
        DB::table('user_notifications')
            ->where('user_id', $request->user()->id)
            ->where('read', true)
            ->delete();

        return response()->json([
            'message' => 'All read notifications deleted',
        ]);
    }

    /**
     * Create a notification (for testing or admin use)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'data' => 'nullable|array',
        ]);

        $id = DB::table('user_notifications')->insertGetId([
            'user_id' => $validated['user_id'],
            'type' => $validated['type'],
            'title' => $validated['title'],
            'message' => $validated['message'],
            'icon' => $validated['icon'] ?? null,
            'color' => $validated['color'] ?? null,
            'data' => isset($validated['data']) ? json_encode($validated['data']) : null,
            'read' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $notification = DB::table('user_notifications')->where('id', $id)->first();

        return response()->json($notification, 201);
    }
}
