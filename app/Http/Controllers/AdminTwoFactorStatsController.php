<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class AdminTwoFactorStatsController extends Controller
{
    /**
     * Vue d'adoption du 2FA pour piloter la décision de fixer/avancer la date
     * d'enforcement : distingue activé / exempté / en attente, plutôt qu'un
     * simple pourcentage qui masquerait les exemptions volontaires.
     */
    public function show(): JsonResponse
    {
        $activeUsers = User::whereNull('disabled_at');

        $total = (clone $activeUsers)->count();
        $enabled = (clone $activeUsers)->withTwoFactorEnabled()->count();
        // Un compte à la fois protégé et exempté compte comme protégé, pas
        // deux fois (sinon `pending` serait sous-estimé).
        $exempt = (clone $activeUsers)
            ->withoutTwoFactorEnabled()
            ->where('two_factor_exempt', true)
            ->where(function ($query) {
                $query->whereNull('two_factor_exempt_until')
                    ->orWhere('two_factor_exempt_until', '>', now());
            })
            ->count();

        $exemptions = User::where('two_factor_exempt', true)
            ->select(['id', 'name', 'email', 'two_factor_exempt_reason', 'two_factor_exempt_until', 'two_factor_exempt_by'])
            ->get()
            ->makeVisible(User::TWO_FACTOR_ADMIN_ATTRIBUTES);

        return response()->json(['data' => [
            'totalActiveUsers' => $total,
            'twoFactorEnabled' => $enabled,
            'exempt' => $exempt,
            'pending' => max(0, $total - $enabled - $exempt),
            'exemptions' => $exemptions,
        ]]);
    }
}
