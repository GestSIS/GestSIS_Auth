<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une session : un appareil connecté à un compte. Son id est le `sid` des
 * access tokens et figure dans le refresh token signé (RefreshTokenCodec),
 * avec la génération : seule la génération courante est valide, une plus
 * ancienne est un rejeu.
 *
 * @property string $id
 * @property int $user_id
 * @property int $refresh_generation
 * @property bool $remember
 * @property \Carbon\CarbonInterface $started_at
 * @property \Carbon\CarbonInterface|null $last_refreshed_at
 * @property \Carbon\CarbonInterface $idle_expires_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property \Carbon\CarbonInterface|null $two_factor_verified_at
 * @property User|null $user
 */
class AuthSession extends Model
{
    /** @use HasFactory<\Database\Factories\AuthSessionFactory> */
    use HasFactory;
    use HasUuids;

    /**
     * Durée maximale d'une session depuis le login, refresh compris : par
     * défaut, et valeurs qu'un admin peut attribuer à un compte (90 jours
     * pour une tablette partagée, voir AdminUserSessionController).
     */
    public const DEFAULT_MAX_DAYS = 30;
    public const ALLOWED_MAX_DAYS = [30, 90];

    /**
     * Fin d'inactivité : sans refresh pendant cette durée, la session expire
     * (« Se souvenir de moi » : 30 jours, sinon 1 jour).
     */
    private const IDLE_DAYS_REMEMBERED = 30;
    private const IDLE_DAYS_NOT_REMEMBERED = 1;

    /**
     * Valeur connue dès la création : la génération est signée dans le
     * premier refresh token, avant toute relecture en base.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'refresh_generation' => 0,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'refresh_generation',
        'remember',
        'started_at',
        'last_refreshed_at',
        'idle_expires_at',
        'ip_address',
        'user_agent',
        'two_factor_verified_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'refresh_generation' => 'integer',
            'remember' => 'boolean',
            'started_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
            'idle_expires_at' => 'datetime',
            'two_factor_verified_at' => 'datetime',
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
     * Fin absolue : login + durée maximale du compte. Le refresh ne la repousse pas.
     */
    public function deadline(): CarbonInterface
    {
        return $this->started_at->copy()->addDays($this->user->sessionMaxDays());
    }

    /**
     * Prochaine fin d'inactivité si la session est renouvelée maintenant.
     */
    public function nextIdleExpiry(): CarbonInterface
    {
        return now()->addDays($this->remember ? self::IDLE_DAYS_REMEMBERED : self::IDLE_DAYS_NOT_REMEMBERED);
    }

    /**
     * Fin effective du refresh token émis maintenant : la plus proche de la
     * fin d'inactivité et de la fin absolue.
     */
    public function refreshTokenExpiry(): CarbonInterface
    {
        $idle = $this->idle_expires_at;
        $deadline = $this->deadline();

        return $idle->lt($deadline) ? $idle : $deadline;
    }

    public function hasExpired(): bool
    {
        return $this->idle_expires_at->isPast() || $this->deadline()->isPast();
    }
}
