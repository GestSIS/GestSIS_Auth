<?php

namespace App\Http\Controllers;

use App\Sis;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use App\User;

class UserController extends Controller
{
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
        $sis = Sis::first('api_key',$sisKey)->first();
        if(is_null($sis)) {
            return response()->json(["error" => "Invalid sis key"], 401);
        }
        
        $ids = array_values((array)DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.sis_id', '=', $sis->id)
            ->select(['user_roles.user_id'])
            ->distinct()
            ->get());
        $ids = array_map(function($r) { return $r->user_id; }, $ids[0]);
        return User::whereIn('id', $ids)->with('userRoles')->get();
    }
}
