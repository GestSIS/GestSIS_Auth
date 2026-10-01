<?php

namespace App\Http\Controllers;

use App\Auth\LoginResponder;
use App\Auth\StepUpVerifier;
use App\Auth\TokenTools;
use App\Auth\TwoFactorManager;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Application d'authentification (TOTP) : enrôlement (enable/confirm),
 * gestion (disable, codes de secours) et vérification d'un code au login.
 * La logique 2FA elle-même vit dans TwoFactorManager et ses facteurs.
 */
class TotpController extends Controller
{
    public function __construct(
        private readonly TwoFactorManager $twoFactor,
        private readonly StepUpVerifier $stepUp,
    ) {
    }

    /**
     * Génère un nouveau secret TOTP (non confirmé). Peut être rappelé tant que
     * le TOTP n'est pas confirmé ; une fois confirmé, `disable` doit être
     * appelé avant de pouvoir reconfigurer.
     */
    public function enable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($this->twoFactor->totp()->isActiveFor($user)) {
            return response()->json(['message' => 'Le TOTP est déjà activé sur ce compte'], 409);
        }

        $this->stepUp->ensure($request, $user);
        $enrollment = $this->twoFactor->totp()->beginEnrollment($user);

        Log::info('TOTP setup started', ['user_id' => $user->id]);

        return response()->json(['data' => $enrollment]);
    }

    /**
     * Confirme le TOTP avec le premier code généré par l'application. Émet
     * les codes de secours si c'est la première méthode 2FA du compte.
     */
    public function confirm(Request $request): JsonResponse
    {
        Validator::make($request->all(), ['code' => ['required', 'string']])->validate();

        /** @var User $user */
        $user = Auth::user();

        $result = $this->twoFactor->enroll(
            $user,
            $this->twoFactor->totp(),
            ['code' => $request->input('code')],
            $request->attributes->get('session_id'),
        );

        Log::info('TOTP enabled', ['user_id' => $user->id]);

        return LoginResponder::respondAfterTwoFactorEnrollment($request, $user, $result['recoveryCodes']);
    }

    /**
     * Désactive le TOTP. Exige le mot de passe et un code TOTP ou de secours,
     * pour qu'un jeton volé ne suffise pas à retirer la protection.
     */
    public function disable(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ])->validate();

        /** @var User $user */
        $user = Auth::user();

        if (!$this->twoFactor->totp()->isActiveFor($user)) {
            return response()->json(['message' => 'Le TOTP n\'est pas activé sur ce compte'], 409);
        }

        // TOTP actif : la ré-authentification exige mot de passe ET code.
        $this->stepUp->ensure($request, $user);
        $this->twoFactor->revoke($user, $this->twoFactor->totp());

        Log::info('TOTP disabled', ['user_id' => $user->id]);

        return response()->json(['message' => 'Le TOTP a été désactivé']);
    }

    /**
     * Régénère les codes de secours (invalide les précédents) : disponible dès
     * qu'une méthode 2FA quelconque est active.
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Aucune méthode 2FA active sur ce compte'], 409);
        }

        $this->stepUp->ensure($request, $user);
        $recoveryCodes = $this->twoFactor->recoveryCodes()->regenerate($user);

        Log::info('2FA recovery codes regenerated', ['user_id' => $user->id]);

        return response()->json(['data' => ['recoveryCodes' => $recoveryCodes]]);
    }

    /**
     * Échange le pre-auth token émis par /login contre un code TOTP ou de
     * secours valide, et renvoie la réponse de connexion complète. Le nombre
     * d'échecs par compte est limité par la route
     * (`throttle-failures:two-factor-verify-failures`). Équivalent WebAuthn :
     * WebauthnController::loginChallenge/loginVerify.
     */
    public function verify(Request $request): JsonResponse
    {
        Validator::make($request->all(), ['code' => ['required', 'string']])->validate();

        try {
            $jwt = TokenTools::validateTwoFactorPreAuthToken($request->bearerToken());
        } catch (Exception|\TypeError $e) {
            return response()->json(['message' => 'Jeton invalide ou expiré'], 401);
        }

        $user = User::findActive($jwt->data->id ?? null);
        if ($user === null || !$user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Jeton invalide ou expiré'], 401);
        }

        if (!$this->twoFactor->verifyCode($user, $request->input('code'))) {
            Log::warning('Invalid 2FA verification attempt', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Code invalide'], 422);
        }

        return LoginResponder::respond($user, null, $request, $jwt->data->remember ?? true);
    }
}
