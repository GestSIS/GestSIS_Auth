<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Role;
use App\Sis;

class RoleController extends Controller
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
        $sis = Sis::first('api_key', $sisKey)->first();
        if (is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }

        return Role::with(["permissionRoles"])->where("roles.sis_id", '=', $sis->id)->get();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function store(Request $request)
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Id', Null);
        $sis = Sis::first('api_key', $sisKey)->first();
        if (is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }

        $data = $request->validate([
            'nom' => 'required|string|min:1',
            'description' => 'string|nullable',
            'sis_id' => 'required|integer|exists:sis,id',
            'permissions.*' => 'integer',
        ]);


        if ($data['sis_id'] != $sis->id) {
            return response()->json(["error" => "Invalid sis id"], 401);
        }

        // Ajout
        $role = new Role($data);
        $role->sis_id = $sis->id;
        $role->save();
        $role->permissions()->attach($data['permissions']);

        return response()->json(['data' => Role::where('id', '=', $role->id)->with('permissionRoles')->first()]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function update(Request $request, int $roleId)
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Id', Null);
        $sis = Sis::first('api_key', $sisKey)->first();
        if (is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }

        $data = $request->validate([
            'id' => 'required|integer|min:1|exists:roles,id',
            'nom' => 'required|string',
            'description' => 'string|nullable',
            'sis_id' => 'required|integer|exists:sis,id',
            'permissions.*' => 'integer',
        ]);

        if ($data['sis_id'] != $sis->id) {
            return response()->json(["error" => "Invalid sis id"], 401);
        }
        if ($data['id'] != $roleId) {
            return response()->json(["error" => "Invalid role id"], 401);
        }

        // Modification
        $role = Role::where('id', '=', $roleId)->first();
        $role->update($data);
        $role->permissions()->sync($data['permissions']);

        return response()->json(['data' => Role::where('id', '=', $roleId)->with('permissionRoles')->first()]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request, int $roleId)
    {
        // Checks pour sisId
        $sisKey = $request->header('Sis-Id', Null);
        $sis = Sis::first('api_key', $sisKey)->first();
        if (is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }

        // Modification
        $role = Role::where('id', '=', $roleId)->where('sis_id', '=', $sis->id)->limit(1)->delete();

        return response()->json(['data' => $role]);
    }
}
