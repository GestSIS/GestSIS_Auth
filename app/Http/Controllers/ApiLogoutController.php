<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Models\RefreshToken;
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
    public function logout(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'token' => ['required', 'string'],
        ])->validate();

        RefreshToken::where('token', TokenTools::hashToken($request->input('token')))->first()?->revokeFamily();

        return response()->json(null, 204);
    }
}
