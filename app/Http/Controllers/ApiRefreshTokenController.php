<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\RefreshToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ApiRefreshTokenController extends Controller
{

    /**
     * Handle a registration request for the application.
     *
     * @param Request $request
     * @return Response
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function refresh(Request $request)
    {
        Log::debug("Call refresh token");

        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        $refreshToken = RefreshToken::where('expire', '>', Carbon::now())->where('token', '=', $request->get('token'))->with('user')->first();
        if(!$refreshToken){
            return response()->json(['error' => 'Refresh token expired'], 401);
        }

        $permissions = DB::table('permissions')
            ->join('permission_roles', 'permissions.id', '=', 'permission_roles.permission_id')
            ->join('roles', 'roles.id', '=', 'permission_roles.role_id')
            ->join('user_roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('sis', 'sis.id', '=', 'roles.sis_id')
            ->where('user_roles.user_id', '=', $refreshToken->user_id)
            ->select('permissions.api_key as perm_key', 'sis.api_key as sis_key')
            ->get();

        $groupedPermissions = array();
        foreach ($permissions as $element) {
            $groupedPermissions[$element->sis_key][] = $element->perm_key;
        }
        
        $accessToken = TokenTools::createAccessToken($refreshToken->user, $groupedPermissions);

        return response()->json(
            array(
                "message" => "Successful login",
                "accessToken" => $accessToken,
                "refreshToken" => $refreshToken->token,
                "user" => $refreshToken->user
            )
        );
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @param array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'token' => ['required', 'string'],
        ]);
    }
}
