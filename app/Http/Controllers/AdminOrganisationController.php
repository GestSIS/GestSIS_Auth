<?php

namespace App\Http\Controllers;

use App\Models\Organisation;
use App\Models\Sis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AdminOrganisationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $domaineFilter = $request->query('domaine');

        $organisations = Organisation::query()
            ->when($domaineFilter, fn ($q, $code) => $q->whereHas('domaine', fn ($q2) => $q2->where('code', $code)))
            ->get();

        Log::info('Accès à la liste des organisations (admin)', [
            'actor_id' => Auth::id(),
            'domaine' => $domaineFilter,
        ]);

        return response()->json(['data' => $organisations]);
    }

    public function show(int $organisationId): JsonResponse
    {
        $organisation = Organisation::with([
            'roles.userRoles.user',
            'sapeurs' => fn ($query) => $query->whereNull('deactivated_at'),
            'sapeurs.user',
        ])->find($organisationId);
        if ($organisation === null) {
            return response()->json(['message' => "Organisation inexistant"], 404);
        }

        return response()->json(['data' => $organisation]);
    }

    /**
     * Crée via `Sis`, pas `Organisation` directement : `SisObserver` est ce qui
     * garde `organisations` synchronisé (domaine_id/nom/org_key/abreviation/
     * mobile), et c'est aussi ce qui fait exister la ligne `sis` dont dépendent
     * la FK `sapeurs.sis_id` et les jointures de `User::getMobile()`/
     * `getSapeurs()`. Un `Organisation::create()` direct ici laisserait une
     * organisation "sis" sans `sis` derrière — aucun sapeur n'y serait liable.
     * Seul domaine géré par l'admin aujourd'hui (cf. OrganisationController).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'org_key' => 'required|string|min:1|unique:organisations,org_key',
            'nom' => 'required|string|min:1',
            'abreviation' => 'string|nullable',
            'mobile' => 'required|boolean',
        ]);

        $sis = new Sis();
        $sis->fill([
            'nom' => $data['nom'],
            'abreviation' => $data['abreviation'] ?? '',
            'api_key' => $data['org_key'],
            'mobile' => $data['mobile'],
        ]);
        $sis->save();

        return response()->json(['data' => Organisation::findOrFail($sis->id)], 201);
    }

    /**
     * Update the specified resource in storage. Passe par `Sis` (voir store()).
     */
    public function update(Request $request, int $organisationId): JsonResponse
    {
        $data = $request->validate([
            'nom' => 'required|string|min:1',
            'abreviation' => 'string|nullable',
            'mobile' => 'required|boolean',
        ]);

        $sis = Sis::find($organisationId);
        if ($sis === null) {
            return response()->json(['message' => "Organisation inexistant"], 404);
        }
        $sis->fill([
            'nom' => $data['nom'],
            'abreviation' => $data['abreviation'] ?? '',
            'mobile' => $data['mobile'],
        ]);
        $sis->save();

        return response()->json(['data' => Organisation::findOrFail($sis->id)]);
    }
}
