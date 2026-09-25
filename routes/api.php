<?php

use App\Http\Controllers\AdminRoleController;
use App\Http\Controllers\AdminSapeurController;
use App\Http\Controllers\AdminTwoFactorExemptionController;
use App\Http\Controllers\AdminTwoFactorPolicyController;
use App\Http\Controllers\AdminTwoFactorStatsController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\AdminUserRoleController;
use App\Http\Controllers\TotpController;
use App\Http\Controllers\TwoFactorStatusController;
use App\Http\Controllers\WebauthnController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ApiLoginController;
use App\Http\Controllers\ApiLogoutController;
use App\Http\Controllers\ApiRegisterController;
use App\Http\Controllers\ApiRefreshTokenController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\ApiConfirmerEmailController;
use App\Http\Controllers\ApiTokenAuthController;
use App\Http\Controllers\ApiTokenController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\MobileVersionController;
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
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('login', [ApiLoginController::class, 'login']);
        Route::post('forgotten-password', [ApiMotDePasseController::class, 'request']);
        Route::post('reset-password', [ApiMotDePasseController::class, 'reset']);
        Route::post('change-password', [ApiMotDePasseController::class, 'changer']);
        Route::post('2fa/verify', [TotpController::class, 'verify']);
        Route::post('2fa/webauthn/challenge', [WebauthnController::class, 'loginChallenge']);
        Route::post('2fa/webauthn/verify', [WebauthnController::class, 'loginVerify']);
    });

    // Enrollment 2FA : accepte un accessToken complet (opt-in volontaire) ou un
    // setup token restreint (parcours forcé par la politique d'enforcement).
    Route::middleware(['jwtTokenTwoFactorEnrollment', 'impersonationReadOnly', 'throttle:10,1'])->group(function () {
        Route::post('2fa/totp/enable', [TotpController::class, 'enable']);
        Route::post('2fa/totp/confirm', [TotpController::class, 'confirm']);
        Route::post('2fa/webauthn/register/challenge', [WebauthnController::class, 'registerChallenge']);
        Route::post('2fa/webauthn/register/verify', [WebauthnController::class, 'registerVerify']);
    });

    // Registration with rate limiting
    Route::middleware('throttle:5,60')->group(function () {
        Route::post('register', [ApiRegisterController::class, 'register']);
    });

    // Moderate limit
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('refresh-token', [ApiRefreshTokenController::class, 'refresh']);
        Route::post('logout', [ApiLogoutController::class, 'logout']);
        Route::post('confirmer-email', [ApiConfirmerEmailController::class, 'confirmerEmail']);
        Route::post('resend-confirmation', [ApiResendConfirmationController::class, 'resend']);
        Route::post('token-auth', [ApiTokenAuthController::class, 'authenticate']);
    });

    Route::get('sis', [SisController::class, 'index']);
    Route::get('mobile/latest-version', [MobileVersionController::class, 'latest']);

    Route::group(['prefix' => 'admin', 'middleware' => 'jwtTokenAdmin'], function () {
        Route::get('token', [ApiLoginController::class, 'token']);
        Route::apiResource('sis', SisController::class, ['as' => 'admin'])->only(['show', 'store', 'update']);
        Route::apiResource('users', AdminUserController::class, ['as' => 'admin'])->only(['index', 'show', 'update', 'destroy']);
        Route::apiResource('roles', AdminRoleController::class, ['as' => 'admin'])->only(['index', 'show', 'update', 'destroy']);
        Route::apiResource('user-roles', AdminUserRoleController::class, ['as' => 'admin'])->only(['store', 'destroy']);
        Route::apiResource('sapeurs', AdminSapeurController::class, ['as' => 'admin'])->only(['destroy']);

        Route::get('2fa/policy', [AdminTwoFactorPolicyController::class, 'show']);
        Route::put('2fa/policy', [AdminTwoFactorPolicyController::class, 'update']);
        Route::get('2fa/stats', [AdminTwoFactorStatsController::class, 'show']);
        Route::post('users/{user_id}/2fa-exemption', [AdminTwoFactorExemptionController::class, 'store']);
        Route::delete('users/{user_id}/2fa-exemption', [AdminTwoFactorExemptionController::class, 'destroy']);
    });

    // `jwtTokenRole` refuse les jetons issus d'un jeton d'API : tout ce qui
    // touche à l'authentification du compte (sessions, 2FA, jetons, jetons de
    // permissions) est réservé à une vraie session. `jwtTokenRoleOrApiToken`
    // ouvre explicitement les quelques routes utiles à une intégration.
    Route::group(['middleware' => 'jwtTokenRoleOrApiToken'], function () {
        Route::get('me', [MeController::class, 'show']);
        Route::get('permissions/', [PermissionController::class, 'index']);
    });

    // `impersonationReadOnly` : en usurpation d'identité, ces réglages se
    // consultent (support) mais ne se modifient pas.
    Route::group(['middleware' => ['jwtTokenRole', 'impersonationReadOnly']], function () {
        Route::post('use-token/', [RegisterTokenController::class, 'consume']);

        Route::get('2fa/status', [TwoFactorStatusController::class, 'show']);
        Route::get('2fa/webauthn/credentials', [WebauthnController::class, 'index']);

        // Actions exigeant mot de passe (+ code) : limite par IP en plus de la
        // limite par compte (HandlesTwoFactorConfirmation::countFailableAttempt).
        Route::middleware('throttle:10,1')->group(function () {
            Route::post('2fa/totp/disable', [TotpController::class, 'disable']);
            Route::post('2fa/totp/recovery-codes', [TotpController::class, 'regenerateRecoveryCodes']);
            Route::delete('2fa/webauthn/credentials/{id}', [WebauthnController::class, 'destroy']);
        });

        // API Token management endpoints
        Route::apiResource('api-tokens', ApiTokenController::class)->only(['index', 'destroy']);

        // Sessions actives (refresh tokens) de l'utilisateur courant
        Route::apiResource('sessions', SessionController::class)->only(['index', 'destroy']);
    });

    // API Token creation with stricter rate limiting (5 tokens per hour)
    Route::middleware(['jwtTokenRole', 'impersonationReadOnly', 'throttle:5,60'])->group(function () {
        Route::apiResource('api-tokens', ApiTokenController::class)->only(['store']);
    });

    Route::group(['middleware' => 'jwtTokenRoleOrApiToken:utilisateur.config'], function () {
        Route::apiResource('roles', RoleController::class)->only(['store', 'update', 'destroy']);
    });

    Route::group(['middleware' => 'jwtTokenRoleOrApiToken:utilisateur.tout'], function () {
        Route::apiResource('roles', RoleController::class)->only(['index']);
        Route::apiResource('roles.users', UserRoleController::class)->only(['index', 'store', 'destroy']);

        Route::apiResource('users', UserController::class)->only(['index']); // With roles
        Route::post('users/{user_id}/roles', [UserRoleController::class, 'updateRoles']); // Allow to update all the role of a user for a given SIS
    });

    // Générer un jeton de permissions reste réservé à une vraie session :
    // combiné à use-token, il permettait à un jeton d'API d'obtenir un access
    // token avec tous les droits du compte.
    Route::group(['middleware' => ['jwtTokenRole:utilisateur.tout', 'impersonationReadOnly']], function () {
        Route::post('register-token', [RegisterTokenController::class, 'newToken']);
    });
});
