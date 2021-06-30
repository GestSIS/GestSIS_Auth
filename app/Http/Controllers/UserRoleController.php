<?php

namespace App\Http\Controllers;

use App\Role;
use App\Sis;
use App\UserRole;
use Illuminate\Http\Request;

class UserRoleController extends Controller
{
    /**
     * Return all roles of a given SIS
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Id', Null);
        $sis = Sis::first('api_key',$sisKey)->first();
        if(is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }

        return Role::with(["userRoles"])->where("roles.sis_id", '=', $sis->id)->get();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @param int $sisId
     * @return Response
     * @throws Exception
     */
    public function store(Request $request, int $roleId)
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Id', Null);
        $sis = Sis::first('api_key',$sisKey)->first();
        if(is_null($sis)) {
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
     *
     * @param Request $request
     * @param int $sisId
     * @return Response
     */
    public function destroy(Request $request, int $roleId, int $userRoleId)
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Id', Null);
        $sis = Sis::first('api_key',$sisKey)->first();
        if(is_null($sis)) {
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
