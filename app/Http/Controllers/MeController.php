<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class MeController extends Controller
{
    /**
     * Confirms the bearer token used to authenticate the request is still
     * valid and returns the associated user, without rotating any token.
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => Auth::user()]);
    }
}
