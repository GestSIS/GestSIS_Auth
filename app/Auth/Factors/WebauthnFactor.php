<?php

namespace App\Auth\Factors;

use App\Auth\WebauthnCeremony;
use App\Models\TwoFactorMethod;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use Webauthn\PublicKeyCredentialDescriptor;

/**
 * Clé de sécurité / biométrie (WebAuthn). Plusieurs clés par compte. Les
 * challenges sont gardés dans le cache entre les deux étapes de chaque
 * cérémonie et effacés à la vérification (pas de rejeu).
 */
class WebauthnFactor implements EnrollableFactor
{
    private const CHALLENGE_TTL_MINUTES = 5;

    public function __construct(private readonly WebauthnCeremony $ceremony)
    {
    }

    public function type(): string
    {
        return TwoFactorMethod::TYPE_WEBAUTHN;
    }

    public function isActiveFor(User $user): bool
    {
        return $this->methods($user)->confirmed()->exists();
    }

    /**
     * Options d'authentification (login) limitées aux clés du compte.
     */
    public function createChallenge(User $user): ?array
    {
        $allowCredentials = $this->descriptorsFor($user);
        if ($allowCredentials === []) {
            throw new TwoFactorException('Aucune clé WebAuthn enregistrée sur ce compte', 422);
        }

        $options = $this->ceremony->buildRequestOptions($allowCredentials);
        Cache::put(self::loginChallengeKey($user), $this->ceremony->serializeOptions($options), now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

        return $this->ceremony->optionsToArray($options);
    }

    /**
     * @param mixed $proof réponse de navigator.credentials.get() (tableau JSON)
     */
    public function verify(User $user, mixed $proof): bool
    {
        $serializedOptions = Cache::pull(self::loginChallengeKey($user));
        if ($serializedOptions === null) {
            throw new TwoFactorException("Aucune authentification WebAuthn en attente, appelez d'abord 2fa/webauthn/challenge", 422);
        }
        $options = $this->ceremony->deserializeRequestOptions($serializedOptions);

        try {
            $credential = $this->ceremony->deserializeCredential(json_encode($proof, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            throw new TwoFactorException('Réponse WebAuthn invalide', 422);
        }

        $stored = $this->methods($user)->confirmed()->where('credential_id', base64_encode($credential->rawId))->first();
        if ($stored === null) {
            Log::warning('Unknown WebAuthn credential used at login', ['user_id' => $user->id]);
            throw new TwoFactorException('Clé WebAuthn inconnue', 422);
        }

        try {
            $record = $this->ceremony->verifyAssertion($stored->toCredentialRecord(), $credential, $options, config('gestsis.webauthn_rp_id'));
        } catch (Throwable $e) {
            Log::warning('Invalid WebAuthn login attempt', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            throw new TwoFactorException('Échec de la vérification WebAuthn', 422);
        }

        // Compteur mis à jour par la librairie : protection anti-clonage.
        $stored->sign_count = $record->counter;
        $stored->last_used_at = now();
        $stored->save();

        return true;
    }

    /**
     * Options de création, en excluant les clés déjà enregistrées.
     */
    public function beginEnrollment(User $user): array
    {
        $options = $this->ceremony->buildCreationOptions($user, $this->descriptorsFor($user));
        Cache::put(self::registerChallengeKey($user), $this->ceremony->serializeOptions($options), now()->addMinutes(self::CHALLENGE_TTL_MINUTES));

        return $this->ceremony->optionsToArray($options);
    }

    /**
     * @param array{name?: string, response?: mixed} $input
     */
    public function completeEnrollment(User $user, array $input): TwoFactorMethod
    {
        $serializedOptions = Cache::pull(self::registerChallengeKey($user));
        if ($serializedOptions === null) {
            throw new TwoFactorException("Aucun enregistrement WebAuthn en attente, appelez d'abord 2fa/webauthn/register/challenge", 422);
        }

        try {
            $options = $this->ceremony->deserializeCreationOptions($serializedOptions);
            $credential = $this->ceremony->deserializeCredential(json_encode($input['response'] ?? null, JSON_THROW_ON_ERROR));
            $record = $this->ceremony->verifyRegistration($credential, $options, config('gestsis.webauthn_rp_id'));
        } catch (Throwable $e) {
            Log::warning('WebAuthn registration failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
            throw new TwoFactorException('Échec de la vérification WebAuthn', 422);
        }

        // L'attestation ("none" uniquement) et le trustPath ne servent qu'à
        // cette vérification : ils ne sont pas conservés.
        return $user->twoFactorMethods()->create([
            'type' => TwoFactorMethod::TYPE_WEBAUTHN,
            'name' => $input['name'] ?? null,
            'credential_id' => base64_encode($record->publicKeyCredentialId),
            'public_key' => base64_encode($record->credentialPublicKey),
            'sign_count' => $record->counter,
            'aaguid' => $record->aaguid->toRfc4122(),
            'transports' => $record->transports,
            'confirmed_at' => now(),
        ]);
    }

    public function revoke(User $user, ?int $methodId = null): void
    {
        $this->methods($user)->when($methodId !== null, fn ($query) => $query->whereKey($methodId))->delete();
    }

    /**
     * @return list<PublicKeyCredentialDescriptor>
     */
    private function descriptorsFor(User $user): array
    {
        return $this->methods($user)->confirmed()->get()
            ->map(fn (TwoFactorMethod $method) => PublicKeyCredentialDescriptor::create('public-key', base64_decode($method->credential_id)))
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<TwoFactorMethod, User>
     */
    private function methods(User $user)
    {
        return $user->twoFactorMethods()->ofType(TwoFactorMethod::TYPE_WEBAUTHN);
    }

    private static function registerChallengeKey(User $user): string
    {
        return "webauthn_register_challenge:{$user->id}";
    }

    private static function loginChallengeKey(User $user): string
    {
        return "webauthn_login_challenge:{$user->id}";
    }
}
