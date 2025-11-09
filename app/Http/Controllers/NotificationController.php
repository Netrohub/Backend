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
            ->where('user_id', $request->user()->id);

        // Filter by read/unread status
        if ($request->has('filter')) {
            if ($request->filter === 'unread') {
                $query->where('read', false);
            } elseif ($request->filter === 'read') {
                $query->where('read', true);
            }
        }

        // Filter by type
        if ($request->has('type') && $request->type !== 'all') {
            $query->where('type', $request->type);
        }

        $query->orderBy('created_at', 'desc');

        // Manual pagination for Query Builder (not Eloquent)
        $page = $request->input('page', 1);
        $perPage = min($request->input('per_page', 20), 100);
        
        $notifications = $query->paginate($perPage, ['*'], 'page', $page);

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
     * Create a notification (admin use - can broadcast to all users)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'nullable|exists:users,id',  // Optional - if null, broadcast to all
            'broadcast' => 'nullable|boolean',  // Broadcast to all users
            'target_role' => 'nullable|in:all,buyer,seller',  // Target specific role
            'type' => 'required|string|max:50',
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'icon' => 'nullable|string|max:50',
            'color' => 'nullable|string|max:50',
            'data' => 'nullable|array',
        ]);

        // If broadcasting or target_role specified, send to multiple users
        if ($validated['broadcast'] ?? false || isset($validated['target_role'])) {
            $usersQuery = \App\Models\User::query();

            // Filter by role if specified
            if (isset($validated['target_role']) && $validated['target_role'] !== 'all') {
                // Note: User model doesn't have buyer/seller roles explicitly
                // This is for future use or can be adapted based on user activity
                // For now, we'll send to all users
            }

            $users = $usersQuery->get();
            $createdCount = 0;

            foreach ($users as $user) {
                DB::table('user_notifications')->insert([
                    'user_id' => $user->id,
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
                $createdCount++;
            }

            return response()->json([
                'message' => "Notification sent to {$createdCount} users",
                'users_count' => $createdCount,
            ], 201);
        }

        // Single user notification
        if (!isset($validated['user_id'])) {
            return response()->json([
                'message' => 'Either user_id or broadcast must be specified',
            ], 400);
        }

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
    
    /**
     * Get all notifications created by admin (for admin panel history)
     */
    public function adminHistory(Request $request)
    {
        // Get recent broadcast notifications (identified by duplicate title/message across users)
        $notifications = DB::table('user_notifications')
            ->select([
                'title',
                'message',
                'type',
                'icon',
                'color',
                DB::raw('COUNT(DISTINCT user_id) as recipients_count'),
                DB::raw('MIN(created_at) as sent_at'),
                DB::raw('MAX(CASE WHEN read = true THEN 1 ELSE 0 END) as any_read')
            ])
            ->groupBy('title', 'message', 'type', 'icon', 'color')
            ->orderByRaw('MIN(created_at) DESC')
            ->limit(50)
            ->get();

        return response()->json($notifications);
    }
}
