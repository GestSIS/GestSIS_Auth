<?php

namespace App\Http\Controllers;

use App\Role;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * Return all roles of a given SIS
     *
     * @param Request $request
     * @return Response
     */
    public function parSis(Request $request, $userId, $sisId)
    {
        //TODO: Add some checks for sisId
        return User::with(["userRoles"])->where("users.sis_id", '=', $sisId)->where("userRoles.user_id", '=', $userId)->all();
    }
}
