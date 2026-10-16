<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Role;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class RoleController extends Controller
{
    /**
     * Return all roles of a given SIS
     */
    public function index(Request $request): JsonResponse
    {
        // Checks pour sisId
        $sisKey = $request->header('Org-Key') ?? $request->header('Sis-Key', Null);
        $organisation = Organisation::where('org_key', $sisKey)->first();
        if (is_null($sisKey) || is_null($organisation)) {
            return response()->json(['message' => "Invalid sis key"], 403);
        }

        return response()->json([
            "data" => Role::with(["permissionRoles"])->where("roles.organisation_id", '=', $organisation->id)->get()
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        // Checks pour sisId
        $sisKey = $request->header('Org-Key') ?? $request->header('Sis-Key', Null);
        $organisation = Organisation::where('org_key', $sisKey)->first();
        if (is_null($sisKey) || is_null($organisation)) {
            return response()->json(['message' => "Invalid sis key"], 403);
        }

        $data = $request->validate([
            'nom' => 'required|string|min:1',
            'description' => 'string|required',
            'organisation_id' => 'required|integer|exists:organisations,id',
            'permissions.*' => 'integer',
        ]);

        if ($data['organisation_id'] != $organisation->id) {
            return response()->json(['message' => "Invalid sis id"], 422);
        }

        // Ajout
        $role = new Role($data);
        $role->organisation_id = $organisation->id;
        $role->save();
        $role->permissions()->attach($data['permissions'] ?? []);

        Log::info('Rôle créé', [
            'actor_id' => Auth::id(),
            'role_id' => $role->id,
            'organisation_id' => $organisation->id,
            'permissions' => $data['permissions'] ?? [],
        ]);

        return response()->json(['data' => Role::where('id', '=', $role->id)->with('permissionRoles')->first()], 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, int $roleId): JsonResponse
    {
        // Checks pour sisId
        $sisKey = $request->header('Org-Key') ?? $request->header('Sis-Key', Null);
        $organisation = Organisation::where('org_key', $sisKey)->first();
        if (is_null($sisKey) || is_null($organisation)) {
            return response()->json(['message' => "Invalid sis key"], 403);
        }

        $data = $request->validate([
            'id' => 'required|integer|min:1|exists:roles,id',
            'nom' => 'required|string',
            'description' => 'string|required',
            'organisation_id' => 'required|integer|exists:organisations,id',
            'permissions.*' => 'integer',
        ]);

        if ($data['organisation_id'] != $organisation->id) {
            return response()->json(['message' => "Invalid sis id"], 422);
        }
        if ($data['id'] != $roleId) {
            return response()->json(['message' => "Invalid role id"], 422);
        }

        // Modification
        $role = Role::where('id', '=', $roleId)->where('organisation_id', '=', $organisation->id)->first();
        if (is_null($role)) {
            return response()->json(['message' => "Role not found or does not belong to this SIS"], 404);
        }
        $role->update($data);
        $role->permissions()->sync($data['permissions'] ?? []);

        Log::info('Rôle modifié', [
            'actor_id' => Auth::id(),
            'role_id' => $roleId,
            'organisation_id' => $organisation->id,
            'permissions' => $data['permissions'] ?? [],
        ]);

        return response()->json(['data' => Role::where('id', '=', $roleId)->with('permissionRoles')->first()]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $roleId): JsonResponse
    {
        // Checks pour sisId
        $sisKey = $request->header('Org-Key') ?? $request->header('Sis-Key', Null);
        $organisation = Organisation::where('org_key', $sisKey)->first();
        if (is_null($sisKey) || is_null($organisation)) {
            return response()->json(['message' => "Invalid sis key"], 403);
        }

        // Modification
        Role::where('id', '=', $roleId)->where('organisation_id', '=', $organisation->id)->limit(1)->delete();

        Log::info('Rôle supprimé', [
            'actor_id' => Auth::id(),
            'role_id' => $roleId,
            'organisation_id' => $organisation->id,
        ]);

        return response()->json(null, 204);
    }
}
