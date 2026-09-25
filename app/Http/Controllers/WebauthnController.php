<?php

namespace App\Http\Controllers;

use App\Auth\LoginResponder;
use App\Auth\TokenTools;
use App\Auth\WebauthnCeremony;
use App\Http\Controllers\Concerns\HandlesTwoFactorConfirmation;
use App\Models\User;
use App\Models\WebauthnCredential;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;
use Webauthn\PublicKeyCredentialDescriptor;

class WebauthnController extends Controller
{
    use HandlesTwoFactorConfirmation;

    private const CHALLENGE_TTL_MINUTES = 5;

    public function __construct(private readonly WebauthnCeremony $ceremony)
    {
    }

    // Enregistrement (compte déjà authentifié, opt-in volontaire ou parcours forcé)

    public function registerChallenge(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        if ($stepUpError = $this->requireStepUpReauthentication($request, $user)) {
            return $stepUpError;
        }

        $options = $this->ceremony->buildCreationOptions($user, $this->descriptorsFor($user));
        Cache::put(
            $this->registerChallengeKey($user->id),
            $this->ceremony->serializeOptions($options),
            now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
        );

        return response()->json(['data' => $this->ceremony->optionsToArray($options)]);
    }

    public function registerVerify(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'response' => ['required'],
        ])->validate();

        /** @var User $user */
        $user = Auth::user();

        $serializedOptions = Cache::pull($this->registerChallengeKey($user->id));
        if ($serializedOptions === null) {
            return response()->json([
                'message' => "Aucun enregistrement WebAuthn en attente, appelez d'abord 2fa/webauthn/register/challenge",
            ], 422);
        }

        try {
            $options = $this->ceremony->deserializeCreationOptions($serializedOptions);
            $credential = $this->ceremony->deserializeCredential(json_encode($request->input('response'), JSON_THROW_ON_ERROR));
            $record = $this->ceremony->verifyRegistration($credential, $options, config('gestsis.webauthn_rp_id'));
        } catch (Throwable $e) {
            Log::warning('WebAuthn registration failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Échec de la vérification WebAuthn'], 422);
        }

        $wasFirstMethod = !$user->hasTwoFactorEnabled();

        WebauthnCredential::fromCredentialRecord($user->id, $request->input('name'), $record);

        Log::info('WebAuthn credential registered', ['user_id' => $user->id]);

        return $this->finalizeTwoFactorMethodConfirmation($request, $user, $wasFirstMethod);
    }

    // Self-service (session déjà authentifiée, jwtTokenRole)

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        return response()->json([
            'data' => $user->webauthnCredentials()
                ->select(['id', 'name', 'created_at', 'last_used_at'])
                ->orderByDesc('created_at')
                ->get(),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $credential = $user->webauthnCredentials()->find($id);
        if ($credential === null) {
            return response()->json(['message' => 'Clé introuvable'], 404);
        }

        // Retirer un moyen 2FA affaiblit le compte au moins autant que d'en
        // ajouter un : sans ça, un jeton de session volé suffirait à
        // supprimer discrètement la protection (voir requireStepUpReauthentication).
        // Le compte a forcément du 2FA actif ici (la clé existe encore), donc
        // le mot de passe est toujours exigé.
        if ($stepUpError = $this->requireStepUpReauthentication($request, $user)) {
            return $stepUpError;
        }

        $credential->delete();

        // Dernière méthode 2FA du compte : les codes de secours n'ont plus lieu
        // d'être (ils seront régénérés à la prochaine méthode confirmée).
        if (!$user->hasTwoFactorEnabled()) {
            $user->twoFactorRecoveryCodes()->delete();
        }

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

        $allowCredentials = $this->descriptorsFor($user);
        if (empty($allowCredentials)) {
            return response()->json(['message' => 'Aucune clé WebAuthn enregistrée sur ce compte'], 422);
        }

        $options = $this->ceremony->buildRequestOptions($allowCredentials);
        Cache::put(
            $this->loginChallengeKey($user->id),
            $this->ceremony->serializeOptions($options),
            now()->addMinutes(self::CHALLENGE_TTL_MINUTES),
        );

        return response()->json(['data' => $this->ceremony->optionsToArray($options)]);
    }

    public function loginVerify(Request $request): JsonResponse
    {
        Validator::make($request->all(), [
            'response' => ['required'],
        ])->validate();

        $user = $this->userFromPreAuthToken($request);
        if ($user === null) {
            return response()->json(['message' => 'Jeton invalide ou expiré'], 401);
        }

        $serializedOptions = Cache::pull($this->loginChallengeKey($user->id));
        if ($serializedOptions === null) {
            return response()->json([
                'message' => "Aucune authentification WebAuthn en attente, appelez d'abord 2fa/webauthn/challenge",
            ], 422);
        }
        $options = $this->ceremony->deserializeRequestOptions($serializedOptions);

        try {
            $credential = $this->ceremony->deserializeCredential(json_encode($request->input('response'), JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            return response()->json(['message' => 'Réponse WebAuthn invalide'], 422);
        }

        $stored = $user->webauthnCredentials()->where('credential_id', base64_encode($credential->rawId))->first();
        if ($stored === null) {
            Log::warning('Unknown WebAuthn credential used at login', ['user_id' => $user->id]);

            return response()->json(['message' => 'Clé WebAuthn inconnue'], 422);
        }

        try {
            $record = $this->ceremony->verifyAssertion($stored->toCredentialRecord(), $credential, $options, config('gestsis.webauthn_rp_id'));
        } catch (Throwable $e) {
            Log::warning('Invalid WebAuthn login attempt', ['user_id' => $user->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'Échec de la vérification WebAuthn'], 422);
        }

        $stored->updateFromCredentialRecord($record);

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

        // Utilisé par loginVerify() pour propager le choix "se souvenir de
        // moi" fait à /login jusqu'à la session complète émise ici.
        $request->attributes->set('two_factor_remember', $jwt->data->remember ?? true);

        return $user;
    }

    /**
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function descriptorsFor(User $user): array
    {
        return $user->webauthnCredentials
            ->map(fn (WebauthnCredential $credential) => PublicKeyCredentialDescriptor::create(
                'public-key',
                base64_decode($credential->credential_id),
            ))
            ->all();
    }

    private function registerChallengeKey(int $userId): string
    {
        return "webauthn_register_challenge:{$userId}";
    }

    private function loginChallengeKey(int $userId): string
    {
        return "webauthn_login_challenge:{$userId}";
    }
}
