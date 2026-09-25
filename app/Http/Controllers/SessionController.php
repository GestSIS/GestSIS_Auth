<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sessions actives (refresh tokens) du compte courant : jusqu'ici, aucun
 * moyen de voir ni de révoquer un appareil précis, seulement une révocation
 * globale (changement de mot de passe, etc.). Une session est une famille de
 * rotation : seul son jeton courant (non consommé, non expiré) la représente
 * dans la liste, mais elle est révoquée en entier.
 */
class SessionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $currentFamilyId = $request->attributes->get('session_family_id');

        $sessions = $user->refreshTokens()
            ->whereNull('used_at')
            ->where('expire', '>', now())
            ->orderByDesc('last_used_at')
            ->get(['id', 'family_id', 'ip_address', 'user_agent', 'remember', 'created_at', 'last_used_at', 'expire']);

        // Chaque refresh crée une nouvelle ligne : l'heure de connexion est
        // celle de la première ligne de la famille, pas du dernier renouvellement.
        $startedAtByFamily = $user->refreshTokens()
            ->whereIn('family_id', $sessions->pluck('family_id')->filter())
            ->groupBy('family_id')
            ->selectRaw('family_id, MIN(created_at) as started_at')
            ->pluck('started_at', 'family_id');

        $data = $sessions->map(fn ($session) => [
            'id' => $session->id,
            'ip_address' => $session->ip_address,
            'user_agent' => $session->user_agent,
            'remember' => $session->remember,
            'created_at' => isset($startedAtByFamily[$session->family_id])
                ? Carbon::parse($startedAtByFamily[$session->family_id])
                : $session->created_at,
            'last_used_at' => $session->last_used_at,
            'expire' => $session->expire,
            'current' => $currentFamilyId !== null && $session->family_id === $currentFamilyId,
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * `id` peut désigner un jeton déjà tourné depuis l'affichage de la liste
     * (l'appareil a fait un refresh entre-temps) : la famille entière est
     * révoquée, successeur compris.
     */
    public function destroy(int $id): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $session = $user->refreshTokens()->find($id);
        if ($session === null) {
            return response()->json(['message' => 'Session introuvable'], 404);
        }

        $session->revokeFamily();

        return response()->json(null, 204);
    }
}
