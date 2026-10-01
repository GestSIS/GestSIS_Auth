<?php

namespace App\Http\Controllers;

use App\Auth\TwoFactorManager;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Statut 2FA du compte courant : actif ou non, et méthodes confirmées (pour
 * les paramètres utilisateur).
 */
class TwoFactorStatusController extends Controller
{
    public function show(TwoFactorManager $twoFactor): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json(['data' => [
            'enabled' => $user->hasTwoFactorEnabled(),
            'availableMethods' => $twoFactor->availableMethods($user),
        ]]);
    }
}
