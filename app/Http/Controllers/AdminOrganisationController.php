<?php

namespace App\Http\Controllers;

use App\Models\Domaine;
use App\Models\Organisation;
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

        $organisation = new Organisation();
        $organisation->fill([
            'nom' => $data['nom'],
            'abreviation' => $data['abreviation'] ?? '',
            'org_key' => $data['org_key'],
            'mobile' => $data['mobile'],
            'domaine_id' => Domaine::where('code', 'sis')->firstOrFail()->id,
        ]);
        $organisation->save();

        return response()->json(['data' => $organisation], 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $organisationId): JsonResponse
    {
        $data = $request->validate([
            'nom' => 'required|string|min:1',
            'abreviation' => 'string|nullable',
            'mobile' => 'required|boolean',
        ]);

        $organisation = Organisation::find($organisationId);
        if ($organisation === null) {
            return response()->json(['message' => "Organisation inexistant"], 404);
        }
        $organisation->fill([
            'nom' => $data['nom'],
            'abreviation' => $data['abreviation'] ?? '',
            'mobile' => $data['mobile'],
        ]);
        $organisation->save();

        return response()->json(['data' => $organisation]);
    }
}
