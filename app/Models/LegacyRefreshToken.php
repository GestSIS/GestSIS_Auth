<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refresh token émis avant les sessions signées (table `refresh_tokens`
 * d'origine, jeton aléatoire stocké haché en SHA-256). Sert uniquement à
 * reprendre ces sessions sans déconnexion (ApiRefreshTokenController) : à
 * supprimer, avec la table, dans une version ultérieure (> 30 jours après le
 * déploiement, plus aucun de ces jetons n'est alors valide).
 *
 * @property int $id
 * @property string $token
 * @property \Carbon\CarbonInterface $expire
 * @property int $user_id
 * @property \Carbon\CarbonInterface|null $created_at
 * @property User|null $user
 */
class LegacyRefreshToken extends Model
{
    protected $table = 'refresh_tokens';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'token',
        'expire',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expire' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User,$this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
