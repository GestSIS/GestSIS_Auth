<?php

namespace App\Http\Controllers;

use App\Models\TwoFactorPolicy;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminTwoFactorPolicyController extends Controller
{
    public function show(): JsonResponse
    {
        $policy = TwoFactorPolicy::current();

        return response()->json(['data' => [
            'enforcedAt' => $policy->enforced_at !== null ? Carbon::parse($policy->enforced_at)->toIso8601String() : null,
        ]]);
    }

    /**
     * Fixe (ou retire, avec enforcedAt: null) la date d'échéance globale à
     * partir de laquelle un compte sans 2FA se fait bloquer au login.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enforcedAt' => ['nullable', 'date'],
        ]);

        $policy = TwoFactorPolicy::current();
        $policy->enforced_at = $data['enforcedAt'] ?? null;
        $policy->updated_by = Auth::id();
        $policy->save();

        Log::info('2FA enforcement policy updated', [
            'admin_id' => Auth::id(),
            'enforced_at' => $policy->enforced_at,
        ]);

        return response()->json(['data' => [
            'enforcedAt' => $policy->enforced_at !== null ? Carbon::parse($policy->enforced_at)->toIso8601String() : null,
        ]]);
    }
}
