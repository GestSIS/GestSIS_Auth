<?php

namespace App\Http\Controllers;

use App\Models\RefreshToken;
use App\Models\PasswordResetToken;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Sapeur;
use App\Models\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = $this->adminUserQuery()->get()->makeVisible(User::TWO_FACTOR_ADMIN_ATTRIBUTES);
        return response()->json(["data" => $users]);
    }

    /**
     * `two_factor_confirmed_at` ne reflète que le TOTP : `webauthn_credentials_exists`
     * permet au client d'afficher aussi les comptes protégés par WebAuthn seul.
     *
     * @return Builder<User>
     */
    private function adminUserQuery(): Builder
    {
        return User::with(['userRoles', 'sapeur'])->withExists('webauthnCredentials');
    }

    /**
     * Modification d'un utilisateur
     */
    public function update(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);
        if ($user == null) {
            return response()->json(['message' => "Utilisateur inexistant"], 404);
        }

        $data = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|min:1',
            'admin' => 'required|boolean',
        ]);

        $user->update($data);
        $user->admin = $data['admin'];
        $user->save();

        return response()->json(['data' => $this->adminUserQuery()->find($userId)?->makeVisible(User::TWO_FACTOR_ADMIN_ATTRIBUTES)]);
    }

    public function show(Request $request, int $userId): JsonResponse
    {
        $user = $this->adminUserQuery()->find($userId);
        if ($user == null) {
            return response()->json(['message' => "Utilisateur inexistant"], 404);
        }
        return response()->json(['data' => $user->makeVisible(User::TWO_FACTOR_ADMIN_ATTRIBUTES)]);
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
        return response()->json(null, 204);
    }
}
