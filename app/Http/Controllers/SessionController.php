<?php

namespace App\Http\Controllers;

use App\Models\AuthSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sessions actives (appareils connectés) du compte courant, avec la
 * possibilité d'en déconnecter une précisément (poste public oublié, appareil
 * perdu) sans changer de mot de passe.
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $currentSessionId = $request->attributes->get('session_id');

        $sessions = $user->authSessions()
            ->where('idle_expires_at', '>', now())
            ->orderByDesc('last_refreshed_at')
            ->get()
            ->map(fn (AuthSession $session) => [
                'id' => $session->id,
                'ip_address' => $session->ip_address,
                'user_agent' => $session->user_agent,
                'remember' => $session->remember,
                'created_at' => $session->started_at,
                'last_used_at' => $session->last_refreshed_at,
                'expire' => $session->idle_expires_at,
                'current' => $currentSessionId !== null && $session->id === $currentSessionId,
            ]);

        return response()->json(['data' => $sessions]);
    }

    public function destroy(string $id): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $deleted = $user->authSessions()->whereKey($id)->delete();
        if ($deleted === 0) {
            return response()->json(['message' => 'Session introuvable'], 404);
        }

        return response()->json(null, 204);
    }
}
