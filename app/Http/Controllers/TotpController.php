<?php

namespace App\Http\Controllers;

use App\Auth\LoginResponder;
use App\Auth\TokenTools;
use App\Http\Controllers\Concerns\HandlesTwoFactorConfirmation;
use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use Exception;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use OTPHP\TOTP;

/**
 * Méthode TOTP du 2FA : enregistrement (enable/confirm), gestion (disable/
 * recovery-codes) et vérification au login (verify) — regroupés ici comme
 * WebauthnController pour l'autre méthode, plutôt que répartis sur plusieurs
 * contrôleurs.
 */
class TotpController extends Controller
{
    use HandlesTwoFactorConfirmation;

    private const OTP_ISSUER = 'GestSIS';

    /**
     * Génère un nouveau secret TOTP pour l'utilisateur courant et le stocke
     * (non confirmé). Peut être rappelé pour régénérer un secret tant que le
     * TOTP n'a pas été confirmé ; une fois confirmé, `disable` doit être
     * appelé avant de pouvoir reconfigurer.
     */
    public function enable(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->hasTotpConfirmed()) {
            return response()->json(['message' => 'Le TOTP est déjà activé sur ce compte'], 409);
        }

        if ($stepUpError = $this->requireStepUpReauthentication($request, $user)) {
            return $stepUpError;
        }

        $totp = TOTP::generate();
        $totp->setLabel($user->email);
        $totp->setIssuer(self::OTP_ISSUER);

        $user->two_factor_secret = $totp->getSecret();
        $user->save();

        Log::info('TOTP setup started', ['user_id' => $user->id]);

        return response()->json([
            'data' => [
                'secret' => $totp->getSecret(),
                'provisioningUri' => $totp->getProvisioningUri(),
            ],
        ]);
    }

    /**
     * Confirme l'activation du TOTP avec le premier code généré par
     * l'application d'authentification, puis émet les codes de secours si
     * c'est la première méthode 2FA confirmée pour ce compte.
     */
    public function confirm(Request $request): JsonResponse
    {
        $this->codeValidator($request->all())->validate();

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasTotpConfirmed()) {
            return response()->json(['message' => 'Le TOTP est déjà activé sur ce compte'], 409);
        }

        if ($user->two_factor_secret === null) {
            return response()->json(['message' => "Aucune configuration TOTP en attente, appelez d'abord 2fa/totp/enable"], 422);
        }

        if (!$user->consumeTotpCode($request->input('code'), allowUnconfirmed: true)) {
            return response()->json(['message' => 'Code invalide'], 422);
        }

        $wasFirstMethod = !$user->hasTwoFactorEnabled();

        $user->two_factor_confirmed_at = now();
        $user->save();

        Log::info('TOTP enabled', ['user_id' => $user->id]);

        return $this->finalizeTwoFactorMethodConfirmation($request, $user, $wasFirstMethod);
    }

    /**
     * Désactive le TOTP. Exige le mot de passe (le JWT seul ne suffit pas à
     * prouver la possession du compte) et un code TOTP ou de secours valide,
     * pour éviter qu'un jeton volé suffise à désactiver la protection. Les
     * codes de secours ne sont purgés que si aucune autre méthode (WebAuthn)
     * ne reste active sur le compte — ce sont un filet de secours pour le
     * compte, pas propres au TOTP.
     */
    public function disable(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ])->validate();

        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasTotpConfirmed()) {
            return response()->json(['message' => 'Le TOTP n\'est pas activé sur ce compte'], 409);
        }

        $attemptsKey = $this->stepUpAttemptsKey($user);
        if ($tooManyAttempts = $this->countFailableAttempt($attemptsKey)) {
            return $tooManyAttempts;
        }

        if (!Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Mot de passe incorrect'], 401);
        }

        if (!$this->verifyCodeOrRecovery($user, $request->input('code'))) {
            return response()->json(['message' => 'Code invalide'], 422);
        }

        RateLimiter::clear($attemptsKey);

        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_used_timestep = null;
        $user->save();

        if (!$user->hasTwoFactorEnabled()) {
            $user->twoFactorRecoveryCodes()->delete();
        }

        Log::info('TOTP disabled', ['user_id' => $user->id]);

        return response()->json(['message' => 'Le TOTP a été désactivé']);
    }

    /**
     * Régénère les codes de secours (invalide les précédents) : filet de
     * secours du compte, pas propre au TOTP — disponible dès qu'une méthode
     * 2FA quelconque est active. Même ré-authentification que pour l'ajout
     * d'une méthode supplémentaire (mot de passe, + code TOTP si c'est la
     * méthode active).
     */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if (!$user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Aucune méthode 2FA active sur ce compte'], 409);
        }

        if ($stepUpError = $this->requireStepUpReauthentication($request, $user)) {
            return $stepUpError;
        }

        $recoveryCodes = TwoFactorRecoveryCode::regenerateFor($user);

        Log::info('2FA recovery codes regenerated', ['user_id' => $user->id]);

        return response()->json(['data' => ['recoveryCodes' => $recoveryCodes]]);
    }

    /**
     * Échange le pre-auth token émis par /login (compte ayant déjà activé le
     * 2FA) contre un code TOTP ou de secours valide, et retourne la réponse
     * de connexion complète — identique à un login classique sans 2FA.
     * Équivalent WebAuthn : WebauthnController::loginChallenge/loginVerify.
     */
    public function verify(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'code' => ['required', 'string'],
        ])->validate();

        try {
            $jwt = TokenTools::validateTwoFactorPreAuthToken($request->bearerToken());
        } catch (Exception|\TypeError $e) {
            return response()->json(['message' => 'Jeton invalide ou expiré'], 401);
        }

        $user = User::findActive($jwt->data->id ?? null);
        if ($user === null || !$user->hasTwoFactorEnabled()) {
            return response()->json(['message' => 'Jeton invalide ou expiré'], 401);
        }

        // Limite par compte, en plus de la limite par IP de la route : sinon un
        // attaquant connaissant le mot de passe répartit ses essais de code sur
        // de nombreuses IP en redemandant des pre-auth tokens.
        $attemptsKey = "2fa-verify:{$user->id}";
        if ($tooManyAttempts = $this->countFailableAttempt($attemptsKey)) {
            return $tooManyAttempts;
        }

        if (!$this->verifyCodeOrRecovery($user, $request->input('code'))) {
            Log::warning('Invalid 2FA verification attempt', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Code invalide'], 422);
        }

        RateLimiter::clear($attemptsKey);

        return LoginResponder::respond($user, null, $request, $jwt->data->remember ?? true);
    }

    private function codeValidator(array $data): ValidatorContract
    {
        return Validator::make($data, [
            'code' => ['required', 'string'],
        ]);
    }
}
