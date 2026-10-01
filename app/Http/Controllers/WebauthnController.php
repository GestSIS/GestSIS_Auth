<?php

namespace App\Http\Controllers;

use App\Auth\LoginResponder;
use App\Auth\StepUpVerifier;
use App\Auth\TokenTools;
use App\Auth\TwoFactorManager;
use App\Models\TwoFactorMethod;
use App\Models\User;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Clés de sécurité / biométrie (WebAuthn) : enregistrement, gestion et
 * vérification au login. La cérémonie elle-même vit dans WebauthnFactor.
 */
class WebauthnController extends Controller
{
    public function __construct(
        private readonly TwoFactorManager $twoFactor,
        private readonly StepUpVerifier $stepUp,
    ) {
    }

    // Enregistrement (compte déjà authentifié, opt-in volontaire ou parcours forcé)

    public function registerChallenge(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $this->stepUp->ensure($request, $user);

        return response()->json(['data' => $this->twoFactor->webauthn()->beginEnrollment($user)]);
    }

    public function registerVerify(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'response' => ['required'],
        ])->validate();

        /** @var User $user */
        $user = Auth::user();

        $result = $this->twoFactor->enroll(
            $user,
            $this->twoFactor->webauthn(),
            ['name' => $request->input('name'), 'response' => $request->input('response')],
            $request->attributes->get('session_id'),
        );

        Log::info('WebAuthn credential registered', ['user_id' => $user->id]);

        return LoginResponder::respondAfterTwoFactorEnrollment($request, $user, $result['recoveryCodes']);
    }

    // Self-service (session déjà authentifiée, jwtTokenRole)

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json([
            'data' => $user->twoFactorMethods()
                ->ofType(TwoFactorMethod::TYPE_WEBAUTHN)
                ->confirmed()
                ->select(['id', 'name', 'created_at', 'last_used_at'])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $exists = $user->twoFactorMethods()->ofType(TwoFactorMethod::TYPE_WEBAUTHN)->whereKey($id)->exists();
        if (!$exists) {
            return response()->json(['message' => 'Clé introuvable'], 404);
        }

        // Retirer un moyen 2FA affaiblit le compte au moins autant que d'en
        // ajouter un : la ré-authentification est exigée ici aussi.
        $this->stepUp->ensure($request, $user);
        $this->twoFactor->revoke($user, $this->twoFactor->webauthn(), $id);

        Log::info('WebAuthn credential removed', ['user_id' => $user->id, 'credential_id' => $id]);

        return response()->json(null, 204);
    }

    // Authentification (login, pre-auth token — voir TotpController::verify pour l'équivalent TOTP)

    public function loginChallenge(Request $request): JsonResponse
    {
        $user = $this->userFromPreAuthToken($request);
        if ($user === null) {
            return response()->json(['message' => 'Jeton invalide ou expiré'], 401);
        }

        return response()->json(['data' => $this->twoFactor->webauthn()->createChallenge($user)]);
    }

    public function loginVerify(Request $request): JsonResponse
    {
        Validator::make($request->all(), ['response' => ['required']])->validate();

        $user = $this->userFromPreAuthToken($request);
        if ($user === null) {
            return response()->json(['message' => 'Jeton invalide ou expiré'], 401);
        }

        if (!$this->twoFactor->webauthn()->verify($user, $request->input('response'))) {
            return response()->json(['message' => 'Échec de la vérification WebAuthn'], 422);
        }

        return LoginResponder::respond($user, null, $request, $request->attributes->get('two_factor_remember', true));
    }

    private function userFromPreAuthToken(Request $request): ?User
    {
        try {
            $jwt = TokenTools::validateTwoFactorPreAuthToken($request->bearerToken());
        } catch (Exception|\TypeError $e) {
            return null;
        }

        $user = User::findActive($jwt->data->id ?? null);
        if ($user === null || !$user->hasTwoFactorEnabled()) {
            return null;
        }

        // Propage le choix "se souvenir de moi" fait à /login jusqu'à la
        // session complète émise par loginVerify().
        $request->attributes->set('two_factor_remember', $jwt->data->remember ?? true);

        return $user;
    }
}
