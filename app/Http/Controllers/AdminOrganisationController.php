<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminOrganisationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $organisations = Organisation::query()
            ->when($request->query('domaine'), fn ($q, $code) => $q->whereHas('domaine', fn ($q2) => $q2->where('code', $code)))
            ->get();

        return response()->json(['data' => $organisations]);
    }
}
