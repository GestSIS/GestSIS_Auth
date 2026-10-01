<?php

namespace App\Http\Controllers;

use App\Models\LegacyRefreshToken;
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
        $users = $this->adminUserQuery()->get()->makeVisible(User::ADMIN_ONLY_ATTRIBUTES);
        return response()->json(["data" => $users]);
    }

    /**
     * `two_factor_enabled` : au moins une méthode 2FA confirmée (TOTP ou clé
     * WebAuthn), même définition que User::hasTwoFactorEnabled().
     *
     * @return Builder<User>
     */
    private function adminUserQuery(): Builder
    {
        return User::with(['userRoles', 'sapeur'])->withExists([
            'twoFactorMethods as two_factor_enabled' => fn (Builder $methods) => $methods->whereNotNull('confirmed_at'),
        ]);
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

        return response()->json(['data' => $this->adminUserQuery()->find($userId)?->makeVisible(User::ADMIN_ONLY_ATTRIBUTES)]);
    }

    public function show(Request $request, int $userId): JsonResponse
    {
        $user = $this->adminUserQuery()->find($userId);
        if ($user == null) {
            return response()->json(['message' => "Utilisateur inexistant"], 404);
        }
        return response()->json(['data' => $user->makeVisible(User::ADMIN_ONLY_ATTRIBUTES)]);
    }

    /**
     * Suppression d'un utilisateur
     */
    public function destroy(Request $request, int $userId): JsonResponse
    {
        // Les sessions (auth_sessions) suivent la suppression du compte (cascade).
        LegacyRefreshToken::where('user_id', '=', $userId)->delete();
        UserRole::where('user_id', '=', $userId)->delete();
        Sapeur::where('user_id', '=', $userId)->delete();
        PasswordResetToken::where('user_id', '=', $userId)->delete();
        User::where('id', '=', $userId)->delete();
        return response()->json(null, 204);
    }
}
