<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\Sis;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserRoleController extends Controller
{
    /**
     * Return all roles of a given SIS
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request)
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Key', $request->header('Sis-Id', Null));
        $sis = Sis::where('api_key', $sisKey)->first();
        if (is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }

        return response()->json([
            "data" => Role::with(["userRoles"])->where("roles.sis_id", '=', $sis->id)->get()
        ]);
    }

    /**
     * Update all the roles for a given user and provided SIS
     */
    public function updateRoles(Request $request, int $userId): JsonResponse
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Key', $request->header('Sis-Id', Null));
        $sis = Sis::where('api_key', $sisKey)->first();
        if (is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }

        $roles = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where("user_roles.user_id", '=', $userId)
            ->where("roles.sis_id", '=', $sis->id)
            ->pluck('user_roles.role_id')
            ->toArray();

        $data = $request->validate([
            'roles.*' => 'nullable|integer|min:1|distinct|exists:roles,id',
        ]);
        $providedRoles = [];
        if (array_key_exists('roles', $data)) {
            $providedRoles = array_map(function ($id) {
                return intval($id);
            }, array_values($data['roles']));
        }

        // Rôles à supprimer
        $rolesToRemove = array_diff($roles, $providedRoles);
        UserRole::where('user_id', '=', $userId)->whereIn('role_id', $rolesToRemove)->delete();

        // Rôles à ajouter
        $rolesToAdd = array_diff($providedRoles, $roles);
        $data = array_map(function ($roleId) use ($userId) {
            return array('role_id' => $roleId, 'user_id' => $userId);
        }, $rolesToAdd);
        UserRole::insert($data);

        // $roles
        $roleIds = Role::where('roles.sis_id', '=', $sis->id)->pluck('id')->toArray();
        $user = UserRole::where('user_id', $userId)->whereIn('user_roles.role_id', $roleIds)->get();
        return response()->json(["data" => $user]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, int $roleId): JsonResponse
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Key', $request->header('Sis-Id', Null));
        $sis = Sis::where('api_key', $sisKey)->first();
        if (is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }

        $data = $request->validate([
            'user_id' => 'required|string|min:1|exists:users,id',
        ]);

        $role = Role::find($roleId);
        if ($role->sis_id != $sis->id) {
            return response()->json(["error" => $role->sis_id], 401);
        }

        // Ajout
        $userRole = new UserRole();
        $userRole->user_id = $data['user_id'];
        $userRole->role_id = $roleId;
        $userRole->save();

        return response()->json(['data' => $userRole]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, int $roleId, int $userRoleId): JsonResponse
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Key', $request->header('Sis-Id', Null));
        $sis = Sis::where('api_key', $sisKey)->first();
        if (is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }

        $role = Role::find($roleId);
        if ($role->sis_id != $sis->id) {
            return response()->json(["error" => $role->sis_id], 401);
        }

        // Modification
        $userRole = UserRole::where('role_id', '=', $roleId)->where('id', '=', $userRoleId)->limit(1)->delete();

        return response()->json(['data' => $userRole]);
    }
}
