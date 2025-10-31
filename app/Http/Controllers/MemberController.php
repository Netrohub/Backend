<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        $members = User::where('is_verified', true)
            ->where('role', 'user')
            ->select('id', 'name', 'avatar', 'bio', 'created_at')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($members);
    }

    public function show($id)
    {
        $member = User::where('is_verified', true)
            ->where('id', $id)
            ->select('id', 'name', 'avatar', 'bio', 'created_at')
            ->withCount(['listings', 'ordersAsSeller', 'ordersAsBuyer'])
            ->firstOrFail();

        return response()->json($member);
    }
}
