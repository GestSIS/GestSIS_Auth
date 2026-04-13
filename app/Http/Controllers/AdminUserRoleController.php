<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;

class AdminUserRoleController extends Controller
{
    /**
     * Modification d'un user_role
     */
    public function store(Request $request): JsonResponse
    {

        $data = $request->validate([
            'role_id' => 'required|integer|exists:roles,id',
            'user_id' => 'required|integer|exists:users,id',
        ]);
        $userRole = UserRole::create($data);

        return response()->json(['data' => $userRole]);
    }

    /**
     * Suppression d'un Role
     */
    public function destroy(Request $request, int $userRoleId): JsonResponse
    {
        UserRole::where('id', '=', $userRoleId)->delete();
        return response()->json(["data" => 'success']);
    }
}
