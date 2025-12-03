<?php

namespace App\Http\Controllers;

use App\Models\RefreshToken;
use App\Models\PasswordResetToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\UserRole;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Key', $request->header('Sis-Id', Null));
        $sis = Sis::where('api_key', $sisKey)->first();
        if (is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }

        // Load users with roles
        $userIds = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.sis_id', '=', $sis->id)
            ->select(['user_roles.user_id as user_id'])
            ->union(
                DB::table('sapeurs')
                    ->where('sapeurs.sis_id', '=', $sis->id)
                    ->select(['sapeurs.user_id as user_id'])
            )
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        $roleIds = Role::where('roles.sis_id', '=', $sis->id)->pluck('id')->toArray();

        $users = User::whereIn('users.id', $userIds)->with([
            'userRoles' => function ($query) use ($roleIds) {
                $query->whereIn('user_roles.role_id', $roleIds);
            },
            'sapeur' => function ($query) use ($sis) {
                $query->where('sapeurs.sis_id', $sis->id);
            }
        ])->get();
        return response()->json(["data" => $users]);
    }
}
