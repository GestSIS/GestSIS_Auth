<?php

namespace App\Models;

use App\Auth\WebauthnUserHandle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Une méthode 2FA d'un compte : application TOTP ou clé WebAuthn. Active
 * seulement une fois confirmée (`confirmed_at`) ; une ligne non confirmée est
 * un enrôlement en cours.
 */
class TwoFactorMethod extends Model
{
    /** @use HasFactory<\Database\Factories\TwoFactorMethodFactory> */
    use HasFactory;

    public const TYPE_TOTP = 'totp';
    public const TYPE_WEBAUTHN = 'webauthn';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'name',
        'secret',
        'last_used_timestep',
        'credential_id',
        'public_key',
        'sign_count',
        'aaguid',
        'transports',
        'confirmed_at',
        'last_used_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'secret',
        'public_key',
        'last_used_timestep',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_used_timestep' => 'integer',
            'sign_count' => 'integer',
            'transports' => 'array',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User,$this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param Builder<TwoFactorMethod> $query
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->whereNotNull('confirmed_at');
    }

    /**
     * @param Builder<TwoFactorMethod> $query
     */
    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    /**
     * Reconstruit un CredentialRecord pour la cérémonie d'authentification
     * WebAuthn. attestationType/trustPath sont des valeurs neutres : seule la
     * cérémonie d'enregistrement en a besoin, jamais celle de login.
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
}
