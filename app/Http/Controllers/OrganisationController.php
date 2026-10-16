<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganisationController extends Controller
{
    /**
     * Liste les organisations du domaine déduit du claim `aud` du token
     * appelant. Aujourd'hui, seul `aud = GestSIS_API` existe (mapping en dur
     * vers le domaine `sis`) ; un futur appelant GestECA ajoutera un second
     * cas sans toucher cette structure.
     */
    public function index(Request $request): JsonResponse
    {
        $jwt = TokenTools::validateToken($request->bearerToken());
        $domaineCode = $jwt->aud === 'GestSIS_API' ? 'sis' : null;

        return response()->json([
            'data' => Organisation::whereHas('domaine', fn ($q) => $q->where('code', $domaineCode))->get(),
        ]);
    }
}
