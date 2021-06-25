<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Role;

class RoleController extends Controller
{
    /**
     * Return all roles of a given SIS
     *
     * @param Request $request
     * @return Response
     */
    public function parSis(Request $request, $sisId)
    {
        //TODO: Add some checks for sisId
        return Role::with(["rolePermissions"])->where("roles.sis_id", '=', $sisId)->get();
    }
}
