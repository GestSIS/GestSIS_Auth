<?php

namespace App\Http\Controllers;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Gestion des sessions d'un compte par un admin : durée maximale (ex. 90 jours
 * pour une tablette partagée) et déconnexion de tous les appareils (appareil
 * perdu/volé). Actions tracées avec l'admin à l'origine.
 */
class AdminUserSessionController extends Controller
{
    /**
     * Fixe la durée maximale des sessions du compte. S'applique aussi aux
     * sessions en cours, dès leur prochain refresh (repasser de 90 à 30 jours
     * coupe donc les sessions déjà plus anciennes que 30 jours).
     */
    public function update(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);
        if ($user === null) {
            return response()->json(['message' => 'Utilisateur inexistant'], 404);
        }

        $data = $request->validate([
            'max_days' => ['required', 'integer', Rule::in(AuthSession::ALLOWED_MAX_DAYS)],
        ]);

        // La valeur par défaut est stockée comme NULL : le compte suit alors le
        // défaut global s'il change un jour.
        $isDefault = (int) $data['max_days'] === AuthSession::DEFAULT_MAX_DAYS;
        $user->session_max_days = $isDefault ? null : $data['max_days'];
        $user->session_max_days_set_by = $isDefault ? null : Auth::id();
        $user->save();

        Log::info('Session maximum lifetime updated', [
            'admin_id' => Auth::id(),
            'user_id' => $user->id,
            'session_max_days' => $user->sessionMaxDays(),
        ]);

        return response()->json(['data' => $user->makeVisible(User::ADMIN_ONLY_ATTRIBUTES)]);
    }

    /**
     * Déconnecte tous les appareils du compte (refresh tokens révoqués ; les
     * access tokens déjà émis expirent d'eux-mêmes, 60 min au plus).
     */
    public function destroy(int $userId): JsonResponse
    {
        $user = User::find($userId);
        if ($user === null) {
            return response()->json(['message' => 'Utilisateur inexistant'], 404);
        }

        $revoked = $user->revokeAllSessions();

        Log::info('All sessions revoked by admin', [
            'admin_id' => Auth::id(),
            'user_id' => $user->id,
            'revoked_sessions' => $revoked,
        ]);

        return response()->json(null, 204);
    }
}
