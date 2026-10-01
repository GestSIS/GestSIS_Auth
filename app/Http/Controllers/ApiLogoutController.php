<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Auth\RefreshTokenCodec;
use App\Models\AuthSession;
use App\Models\LegacyRefreshToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Déconnexion côté serveur : jusqu'ici, "se déconnecter" ne faisait que
 * vider le stockage local du front — le refresh token restait valide côté
 * serveur jusqu'à ses 30 jours naturels. Sans middleware d'authentification
 * volontairement : posséder le refresh token est la preuve suffisante,
 * exactement comme pour /refresh-token.
 */
class ApiLogoutController extends Controller
{
    /**
     * Supprime la session du refresh token reçu (quelle que soit sa
     * génération : un jeton déjà renouvelé ne doit pas laisser la session
     * active). Réponse identique que le jeton soit valide ou non.
     */
    public function logout(Request $request, RefreshTokenCodec $codec): JsonResponse
    {
        Validator::make($request->all(), [
            'token' => ['required', 'string'],
        ])->validate();
        $token = $request->input('token');

        if (RefreshTokenCodec::isLegacyToken($token)) {
            LegacyRefreshToken::where('token', TokenTools::hashToken($token))->delete();
        } elseif (($claims = $codec->decode($token)) !== null) {
            AuthSession::whereKey($claims['sid'])->delete();
        }

        return response()->json(null, 204);
    }
}
