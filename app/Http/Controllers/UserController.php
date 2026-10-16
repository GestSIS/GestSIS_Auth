<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\Organisation;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Checks pour sisId
        $sisKey = $request->header('Org-Key') ?? $request->header('Sis-Key', Null);
        $organisation = Organisation::where('org_key', $sisKey)->first();
        if (is_null($organisation)) {
            return response()->json(['message' => "Invalid sis key"], 403);
        }

        // Load users with roles
        $userIds = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.organisation_id', '=', $organisation->id)
            ->select(['user_roles.user_id as user_id'])
            ->union(
                DB::table('sapeurs')
                    ->where('sapeurs.sis_id', '=', $organisation->id)
                    ->select(['sapeurs.user_id as user_id'])
            )
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        $roleIds = Role::where('roles.organisation_id', '=', $organisation->id)->pluck('id')->toArray();

        $users = User::whereIn('users.id', $userIds)->with([
            'userRoles' => function ($query) use ($roleIds) {
                $query->whereIn('user_roles.role_id', $roleIds);
            },
            'sapeur' => function ($query) use ($organisation) {
                $query->where('sapeurs.sis_id', $organisation->id);
            }
        ])->get();
        return response()->json(["data" => $users]);
    }
}
