<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use App\Helpers\PaginationHelper;
use Illuminate\Support\Facades\Cache;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        // Allow higher per_page for members list (up to 1000 to get all members)
        $page = $request->get('page', 1);
        $perPage = min((int)($request->get('per_page', 20)), 1000); // Allow up to 1000 per page
        $cacheKey = 'members_list_page_' . $page . '_per_page_' . $perPage;
        
        if ($page === 1 && $perPage <= 100) {
            // Cache only for reasonable page sizes to avoid cache bloat
            $members = Cache::remember($cacheKey, 600, function () use ($request, $perPage) {
                return PaginationHelper::paginate(
                    User::where(function($query) {
                            $query->where('is_verified', true)
                                  ->orWhereNotNull('email_verified_at');
                        })
                        ->where('role', 'user')
                        ->select('id', 'username', 'display_name', 'name', 'avatar', 'bio', 'created_at')
                        ->orderBy('created_at', 'desc'),
                    $request,
                    $perPage, // Use custom per_page
                    1000 // Max per_page allowed
                );
            });
        } else {
            $members = PaginationHelper::paginate(
                User::where(function($query) {
                        $query->where('is_verified', true)
                              ->orWhereNotNull('email_verified_at');
                    })
                    ->where('role', 'user')
                    ->select('id', 'username', 'display_name', 'name', 'avatar', 'bio', 'created_at')
                    ->orderBy('created_at', 'desc'),
                $request,
                $perPage, // Use custom per_page
                1000 // Max per_page allowed
            );
        }

        return response()->json($members);
    }

    public function show($id)
    {
        // Support both ID and username lookup
        $isUsername = !is_numeric($id);
        
        // Cache individual member profiles for 15 minutes
        $cacheKey = 'member_profile_' . ($isUsername ? 'username_' . $id : $id);
        
        $member = Cache::remember($cacheKey, 900, function () use ($id, $isUsername) {
            $query = User::where(function($q) {
                    $q->where('is_verified', true)
                      ->orWhereNotNull('email_verified_at');
                });
            
            if ($isUsername) {
                $query->where('username', $id);
            } else {
                $query->where('id', $id);
            }
            
            return $query->select('id', 'username', 'display_name', 'name', 'avatar', 'bio', 'created_at')
                ->withCount(['listings', 'ordersAsSeller', 'ordersAsBuyer'])
                ->firstOrFail();
        });

        return response()->json($member);
    }
}
