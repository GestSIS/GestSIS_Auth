<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiLoginController;
use App\Http\Controllers\ApiRegisterController;
use App\Http\Controllers\ApiRefreshTokenController;
use App\Http\Controllers\ApiConfirmerEmailController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\RegisterTokenController;
use App\Http\Controllers\SisController;

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
    Route::post('confirmer-email', [ApiConfirmerEmailController::class, 'confirmerEmail']);

    Route::get('sis', [SisController::class, 'index']);
    Route::post('use-token/', [RegisterTokenController::class, 'consume']);

    Route::group(['middleware' => 'jwtTokenRole'], function () {
        Route::get('permissions/', [PermissionController::class, 'index']);
    });

    Route::group(['middleware' => 'jwtTokenRole:utilisateur.config'], function () {
        Route::resource('roles', 'RoleController')->only(['index', 'store', 'update', 'destroy']);
    });

    Route::group(['middleware' => 'jwtTokenRole:utilisateur.tout'], function () {
        Route::resource('roles', 'RoleController')->only(['index']);
        Route::post('register-token', [RegisterTokenController::class, 'newToken']);
        Route::resource('roles/{role_id}/users', 'UserRoleController')->only(['index', 'store', 'destroy']);
        Route::post('users/{user_id}/roles', [UserRoleController::class, 'updateRoles']); // Allow to update all the role of a user for a given SIS
        Route::get('users', [UserController::class, 'parSis']); // With roles
    });
});
