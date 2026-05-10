<?php

use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminUserRoleController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiLoginController;
use App\Http\Controllers\ApiRegisterController;
use App\Http\Controllers\ApiRefreshTokenController;
use App\Http\Controllers\ApiConfirmerEmailController;
use App\Http\Controllers\ApiTokenAuthController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserRoleController;
use App\Http\Controllers\RegisterTokenController;
use App\Http\Controllers\SisController;
use App\Http\Controllers\ApiMotDePasseController;
use App\Http\Controllers\ApiResendConfirmationController;
use App\Http\Controllers\RoleController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::group(['prefix' => 'v1'], function () {

    // Auth endpoints with strict rate limiting
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('login', [ApiLoginController::class, 'login']);
        Route::post('forgotten-password', [ApiMotDePasseController::class, 'request']);
        Route::post('reset-password', [ApiMotDePasseController::class, 'reset']);
        Route::post('change-password', [ApiMotDePasseController::class, 'changer']);
    });

    // Registration with rate limiting
    Route::middleware('throttle:5,60')->group(function () {
        Route::post('register', [ApiRegisterController::class, 'register']);
    });

    // Moderate limit
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('refresh-token', [ApiRefreshTokenController::class, 'refresh']);
        Route::post('confirmer-email', [ApiConfirmerEmailController::class, 'confirmerEmail']);
        Route::post('token-auth', [ApiTokenAuthController::class, 'authenticate']);
    });

    Route::get('sis', [SisController::class, 'index']);

    Route::group(['prefix' => 'admin', 'middleware' => 'jwtTokenAdmin'], function () {
        Route::get('token', [ApiLoginController::class, 'token']);
        Route::resource('sis', SisController::class, ['as' => 'admin'])->only(['store', 'update']);
        Route::resource('users', AdminUserController::class, ['as' => 'admin'])->only(['index', 'show', 'update', 'destroy']);
        Route::resource('roles', AdminRoleController::class, ['as' => 'admin'])->only(['index', 'show', 'update', 'destroy']);
        Route::resource('user-roles', AdminUserRoleController::class, ['as' => 'admin'])->only(['store', 'destroy']);
    });

    Route::group(['middleware' => 'jwtTokenRole'], function () {
        Route::get('permissions/', [PermissionController::class, 'index']);
        Route::post('resend-confirmation/', [ApiResendConfirmationController::class, 'resend']);
        Route::post('use-token/', [RegisterTokenController::class, 'consume']);

        // API Token management endpoints
        Route::apiResource('user/tokens', ApiTokenController::class)->only(['index', 'destroy']);
    });

    // API Token creation with stricter rate limiting (5 tokens per hour)
    Route::middleware(['jwtTokenRole', 'throttle:5,60'])->group(function () {
        Route::apiResource('user/tokens', ApiTokenController::class)->only(['store']);
    });

    Route::group(['middleware' => 'jwtTokenRole:utilisateur.config'], function () {
        Route::resource('roles', RoleController::class)->only(['store', 'update', 'destroy']);
    });

    Route::group(['middleware' => 'jwtTokenRole:utilisateur.tout'], function () {
        Route::resource('roles', RoleController::class)->only(['index']);
        Route::resource('roles.users', UserRoleController::class)->only(['index', 'store', 'destroy']);

        Route::post('register-token', [RegisterTokenController::class, 'newToken']);

        Route::resource('users', UserController::class)->only(['index']); // With roles
        Route::post('users/{user_id}/roles', [UserRoleController::class, 'updateRoles']); // Allow to update all the role of a user for a given SIS
    });
});
