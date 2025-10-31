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
        // Cache member list for 10 minutes (first page only)
        $page = $request->get('page', 1);
        $cacheKey = 'members_list_page_' . $page;
        
        if ($page === 1) {
            $members = Cache::remember($cacheKey, 600, function () use ($request) {
                return PaginationHelper::paginate(
                    User::where('is_verified', true)
                        ->where('role', 'user')
                        ->select('id', 'name', 'avatar', 'bio', 'created_at')
                        ->orderBy('created_at', 'desc'),
                    $request
                );
            });
        } else {
            $members = PaginationHelper::paginate(
                User::where('is_verified', true)
                    ->where('role', 'user')
                    ->select('id', 'name', 'avatar', 'bio', 'created_at')
                    ->orderBy('created_at', 'desc'),
                $request
            );
        }

        return response()->json($members);
    }

    public function show($id)
    {
        // Cache individual member profiles for 15 minutes
        $cacheKey = 'member_profile_' . $id;
        
        $member = Cache::remember($cacheKey, 900, function () use ($id) {
            return User::where('is_verified', true)
                ->where('id', $id)
                ->select('id', 'name', 'avatar', 'bio', 'created_at')
                ->withCount(['listings', 'ordersAsSeller', 'ordersAsBuyer'])
                ->firstOrFail();
        });

        return response()->json($member);
    }
}
