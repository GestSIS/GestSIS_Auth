<?php

namespace App\Models;

use App\Auth\WebauthnUserHandle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

class WebauthnCredential extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'credential_id',
        'public_key',
        'sign_count',
        'aaguid',
        'transports',
        'name',
        'last_used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sign_count' => 'integer',
            'transports' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Persiste un CredentialRecord fraîchement validé par webauthn-lib (après
     * une cérémonie d'enregistrement). L'attestation ("none" uniquement, voir
     * WebauthnCeremony) et le trustPath ne sont pas conservés : ils ne servent
     * qu'à la vérification d'enregistrement, jamais relus ensuite.
     */
    public static function fromCredentialRecord(int $userId, string $name, CredentialRecord $record): self
    {
        return self::create([
            'user_id' => $userId,
            'credential_id' => base64_encode($record->publicKeyCredentialId),
            'public_key' => base64_encode($record->credentialPublicKey),
            'sign_count' => $record->counter,
            'aaguid' => $record->aaguid->toRfc4122(),
            'transports' => $record->transports,
            'name' => $name,
        ]);
    }

    /**
     * Reconstruit un CredentialRecord pour la cérémonie d'authentification
     * (login). attestationType/trustPath sont des valeurs neutres : la
     * cérémonie de login (requestCeremony) ne les utilise jamais, seule la
     * cérémonie d'enregistrement (creationCeremony) en a besoin.
     */
    public function toCredentialRecord(): CredentialRecord
    {
        return CredentialRecord::create(
            publicKeyCredentialId: base64_decode($this->credential_id),
            type: 'public-key',
            transports: $this->transports ?? [],
            attestationType: 'none',
            trustPath: EmptyTrustPath::create(),
            aaguid: Uuid::fromString($this->aaguid),
            credentialPublicKey: base64_decode($this->public_key),
            userHandle: WebauthnUserHandle::forUserId($this->user_id),
            counter: $this->sign_count,
        );
    }

    /**
     * Applique le compteur mis à jour par AuthenticatorAssertionResponseValidator
     * après une authentification réussie (protection anti-clonage).
     */
    public function updateFromCredentialRecord(CredentialRecord $record): void
    {
        $this->sign_count = $record->counter;
        $this->last_used_at = now();
        $this->save();
    }
}
