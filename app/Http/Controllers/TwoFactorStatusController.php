<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Statut 2FA du compte courant, indépendant de la méthode (utile au front
 * pour savoir quelles méthodes afficher comme actives dans les paramètres
 * utilisateur — `hasTwoFactorEnabled()` est l'ombrelle "au moins une méthode
 * active", pas spécifique au TOTP ni à WebAuthn).
 */
class TwoFactorStatusController extends Controller
{
    public function show(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json(['data' => [
            'enabled' => $user->hasTwoFactorEnabled(),
            'availableMethods' => $user->twoFactorAvailableMethods(),
        ]]);
    }
}
