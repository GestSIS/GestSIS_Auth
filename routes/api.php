<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiLoginController;
use App\Http\Controllers\ApiRegisterController;
use App\Http\Controllers\ApiRefreshTokenController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::group(['prefix' => 'v1'], function () {

    Route::post('login', [ApiLoginController::class, 'login']);
    Route::post('register', [ApiRegisterController::class, 'register']);
    Route::post('refresh-token', [ApiRefreshTokenController::class, 'refresh']);

    // Route::middleware('auth:token')->get('/test', function (Request $request) {
    //     return "TEST";
    // });

    Route::group(['middleware' => 'jwtToken'], function () {
        Route::get('user-info/{id}', [UserController::class, 'info']);

        Route::get('roles/{sis_id}/', [RoleController::class, 'parSis']);
        Route::get('user/{user_id}/roles/{sis_id}', [UserRoleController::class, 'parSis']);

        Route::get('permissions/', [PermissionController::class, 'all']);
        Route::get('users/{sis_id}', [UserController::class, 'parSis']); 
    });

    // TODO: Ajout partie admin pour la gestion des permissions, SIS, ...
});
