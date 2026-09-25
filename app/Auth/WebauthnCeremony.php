<?php

namespace App\Auth;

use App\Models\User;
use Cose\Algorithms;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Encapsule le câblage de web-auth/webauthn-lib (attestation "none" uniquement
 * — pas de vérification de chaîne de confiance fabricant, inutile pour un
 * outil interne) : construction des options, (dé)sérialisation JSON, et
 * validation des deux cérémonies (enregistrement / authentification).
 */
class WebauthnCeremony
{
    private const CHALLENGE_LENGTH = 32;

    public function rpEntity(): PublicKeyCredentialRpEntity
    {
        return PublicKeyCredentialRpEntity::create(
            config('gestsis.webauthn_rp_name'),
            config('gestsis.webauthn_rp_id'),
        );
    }

    /**
     * @param list<PublicKeyCredentialDescriptor> $excludeCredentials
     */
    public function buildCreationOptions(User $user, array $excludeCredentials = []): PublicKeyCredentialCreationOptions
    {
        return PublicKeyCredentialCreationOptions::create(
            rp: $this->rpEntity(),
            user: PublicKeyCredentialUserEntity::create(
                $user->email,
                WebauthnUserHandle::forUserId($user->id),
                $user->name,
            ),
            challenge: random_bytes(self::CHALLENGE_LENGTH),
            pubKeyCredParams: [
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_ES256),
                PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_RS256),
            ],
            excludeCredentials: $excludeCredentials,
        );
    }

    /**
     * @param list<PublicKeyCredentialDescriptor> $allowCredentials
     */
    public function buildRequestOptions(array $allowCredentials): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(self::CHALLENGE_LENGTH),
            rpId: config('gestsis.webauthn_rp_id'),
            allowCredentials: $allowCredentials,
        );
    }

    /**
     * Sérialise des options (creation ou request) en tableau JSON-compatible,
     * dans le format attendu par @simplewebauthn/browser côté front
     * (startRegistration()/startAuthentication()).
     */
    public function optionsToArray(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): array
    {
        return json_decode($this->serializer()->serialize($options, 'json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @throws \Throwable si le JSON ne correspond pas à une réponse WebAuthn valide
     */
    public function deserializeCredential(string $json): PublicKeyCredential
    {
        return $this->serializer()->deserialize($json, PublicKeyCredential::class, 'json');
    }

    /**
     * Sérialise des options en JSON pour un stockage cache : les objets de
     * web-auth/webauthn-lib sont `readonly` et ne supportent pas le
     * serialize()/unserialize() natif de PHP utilisé par le cache "file".
     */
    public function serializeOptions(PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options): string
    {
        return $this->serializer()->serialize($options, 'json');
    }

    public function deserializeCreationOptions(string $json): PublicKeyCredentialCreationOptions
    {
        return $this->serializer()->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');
    }

    public function deserializeRequestOptions(string $json): PublicKeyCredentialRequestOptions
    {
        return $this->serializer()->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');
    }

    /**
     * @throws \Webauthn\Exception\AuthenticatorResponseVerificationException
     */
    public function verifyRegistration(
        PublicKeyCredential $credential,
        PublicKeyCredentialCreationOptions $options,
        string $host,
    ): CredentialRecord {
        if (!$credential->response instanceof AuthenticatorAttestationResponse) {
            throw new \UnexpectedValueException('Réponse d\'enregistrement WebAuthn invalide');
        }

        $factory = $this->ceremonyStepManagerFactory();

        return AuthenticatorAttestationResponseValidator::create($factory->creationCeremony())
            ->check($credential->response, $options, $host);
    }

    /**
     * @throws \Webauthn\Exception\AuthenticatorResponseVerificationException
     */
    public function verifyAssertion(
        CredentialRecord $storedCredential,
        PublicKeyCredential $credential,
        PublicKeyCredentialRequestOptions $options,
        string $host,
    ): CredentialRecord {
        if (!$credential->response instanceof AuthenticatorAssertionResponse) {
            throw new \UnexpectedValueException('Réponse d\'authentification WebAuthn invalide');
        }

        $factory = $this->ceremonyStepManagerFactory();

        return AuthenticatorAssertionResponseValidator::create($factory->requestCeremony())
            ->check($storedCredential, $credential->response, $options, $host, $storedCredential->userHandle);
    }

    private function ceremonyStepManagerFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        $factory->setAllowedOrigins(config('gestsis.webauthn_allowed_origins'));

        return $factory;
    }

    private function serializer(): \Symfony\Component\Serializer\SerializerInterface
    {
        $attestationStatementSupportManager = new AttestationStatementSupportManager([
            new NoneAttestationStatementSupport(),
        ]);

        return (new WebauthnSerializerFactory($attestationStatementSupportManager))->create();
    }
}
