<?php

namespace App\Http\Controllers;

use App\Models\RefreshToken;
use App\Models\PasswordResetToken;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Sapeur;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::with(['userRoles', 'sapeur'])->get();
        return response()->json(["data" => $users]);
    }

    /**
     * Modification d'un utilisateur
     */
    public function update(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);
        if ($user == null) {
            return response()->json(['error' => "Utilisateur inexistant"]);
        }

        $data = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|min:1',
            'admin' => 'required|boolean',
        ]);

        $user->update($data);
        $user->admin = $data['admin'];
        $user->save();

        return response()->json(['data' => User::with(['userRoles', 'sapeur'])->find($userId)]);
    }

    public function show(Request $request, int $userId): JsonResponse
    {
        $user = User::with(['userRoles', 'sapeur'])->find($userId);
        if ($user == null) {
            return response()->json(['error' => "Utilisateur inexistant"]);
        }
        return response()->json(['data' => $user]);
    }

    /**
     * Suppression d'un utilisateur
     */
    public function destroy(Request $request, int $userId): JsonResponse
    {
        RefreshToken::where('user_id', '=', $userId)->delete();
        UserRole::where('user_id', '=', $userId)->delete();
        Sapeur::where('user_id', '=', $userId)->delete();
        PasswordResetToken::where('user_id', '=', $userId)->delete();
        User::where('id', '=', $userId)->delete();
        return response()->json(["data" => 'success']);
    }
}
