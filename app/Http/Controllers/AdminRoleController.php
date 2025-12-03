<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Role;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;

class AdminRoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $roles = Role::with(['permissions'])->get();
        return response()->json(["data" => $roles]);
    }

    /**
     * Modification d'un role
     */
    public function update(Request $request, int $roleId): JsonResponse
    {
        $role = Role::find($roleId);
        if ($role == null) {
            return response()->json(['error' => "Role inexistant"]);
        }

        $data = $request->validate([
            'nom' => 'required|string|min:1',
            'description' => 'string|required',
            'sis_id' => 'required|integer|exists:sis,id',
            'permissions.*' => 'integer',
        ]);

        $role->update($data);
        $role->save();

        return response()->json(['data' => Role::with(['permissions'])->find($roleId)->get()]);
    }

    public function show(Request $request, int $roleId): JsonResponse
    {
        $role = Role::with(['permissions'])->find($roleId);
        if ($role == null) {
            return response()->json(['error' => "Role inexistant"]);
        }
        return response()->json(['data' => $role]);
    }

    /**
     * Suppression d'un Role
     */
    public function destroy(Request $request, int $roleId): JsonResponse
    {
        UserRole::where('role_id', '=', $roleId)->delete();
        Role::where('id', '=', $roleId)->delete();
        return response()->json(["data" => 'success']);
    }
}
