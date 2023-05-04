<?php

namespace App\Http\Controllers;

use App\Models\RefreshToken;
use App\Models\ResetPasswordToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Role;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\UserRole;

class UserController extends Controller
{
    public function index(Request $request)
    {
        // Load users with roles
        $userIds = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->select(['user_roles.user_id as user_id'])
            ->union(
                DB::table('sapeurs')
                    ->select(['sapeurs.user_id as user_id'])
            )
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        // $roleIds = Role::pluck('id')->toArray();

        $users = User::whereIn('users.id', $userIds)->with([
            'userRoles'
            //  => function ($query) use ($roleIds) {
            //     $query->whereIn('user_roles.role_id', $roleIds);
            // }
            , 'sapeur'
        ])->get();
        return response()->json(["data" => $users]);
    }

    /**
     * Modification d'un utilisateur
     *
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function update(Request $request, int $userId)
    {
        $user = User::find($userId);
        if ($user == null) {
            return response()->json(['error' => "Utilisateur inexistant"]);
        }

        $data = $request->validate([
            'email' => 'required|email',
            'name' => 'required|string|min:1',
            'admin' => 'required|boolean',
        ]);

        $user->update($data);
        $user->admin = $data['admin'];
        $user->save();

        return response()->json(['data' => User::with(['userRoles', 'sapeur'])->find($userId)->get()]);
    }

    /**
     * Suppression d'un utilisateur
     */
    public function destroy(Request $request, int $userId)
    {
        RefreshToken::where('user_id', '=', $userId)->delete();
        UserRole::where('user_id', '=', $userId)->delete();
        Sapeur::where('user_id', '=', $userId)->delete();
        ResetPasswordToken::where('user_id', '=', $userId)->delete();
        User::where('id', '=', $userId)->delete();
        return response()->json(["data" => 'success']);
    }

    /**
     * Return all roles of a given SIS
     *
     * @param Request $request
     * @return Response
     */
    public function parSis(Request $request)
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Id', Null);
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

        $users = User::whereIn('users.id', $userIds)->with(['userRoles' => function ($query) use ($roleIds) {
            $query->whereIn('user_roles.role_id', $roleIds);
        }, 'sapeur' => function ($query) use ($sis) {
            $query->where('sapeurs.sis_id', $sis->id);
        }])->get();
        return response()->json(["data" => $users]);
    }
}
