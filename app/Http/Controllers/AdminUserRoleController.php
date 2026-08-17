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

        $existing = UserRole::where('user_id', $data['user_id'])
            ->where('role_id', $data['role_id'])
            ->first();

        if ($existing !== null) {
            return response()->json([
                'error' => ['role_id' => ['Cet utilisateur a déjà ce rôle.']]
            ], 422);
        }

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
