<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminTwoFactorExemptionController extends Controller
{
    /**
     * Accorde une exemption 2FA à un compte (ex. tablette pas encore migrée
     * vers un token API). `reason` est obligatoire pour garder une trace
     * auditable de pourquoi ce compte contourne l'enforcement.
     */
    public function store(Request $request, int $userId): JsonResponse
    {
        $user = User::find($userId);
        if ($user === null) {
            return response()->json(['message' => 'Utilisateur inexistant'], 404);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3'],
            'until' => ['nullable', 'date', 'after:now'],
        ]);

        $user->two_factor_exempt = true;
        $user->two_factor_exempt_reason = $data['reason'];
        $user->two_factor_exempt_until = $data['until'] ?? null;
        $user->two_factor_exempt_by = Auth::id();
        $user->save();

        Log::info('2FA exemption granted', [
            'admin_id' => Auth::id(),
            'user_id' => $user->id,
            'reason' => $data['reason'],
            'until' => $user->two_factor_exempt_until,
        ]);

        return response()->json(['data' => $user->makeVisible(User::TWO_FACTOR_ADMIN_ATTRIBUTES)]);
    }

    public function destroy(int $userId): JsonResponse
    {
        $user = User::find($userId);
        if ($user === null) {
            return response()->json(['message' => 'Utilisateur inexistant'], 404);
        }

        $user->two_factor_exempt = false;
        $user->two_factor_exempt_reason = null;
        $user->two_factor_exempt_until = null;
        $user->two_factor_exempt_by = null;
        $user->save();

        // Pas de révocation des sessions ici : ApiRefreshTokenController
        // réévalue l'obligation 2FA à chaque refresh et ne coupe que si elle
        // s'applique réellement (compte sans 2FA, échéance dépassée).

        Log::info('2FA exemption revoked', [
            'admin_id' => Auth::id(),
            'user_id' => $user->id,
        ]);

        return response()->json(null, 204);
    }
}
