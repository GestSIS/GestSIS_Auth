<?php

namespace App\Http\Controllers;

use App\Models\Sapeur;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSapeurController extends Controller
{
    /**
     * Suppression d'un lien sapeur/utilisateur
     */
    public function destroy(Request $request, int $sapeurId): JsonResponse
    {
        Sapeur::where('id', '=', $sapeurId)->delete();
        return response()->json(["data" => 'success']);
    }
}
