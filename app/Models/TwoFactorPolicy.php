<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TwoFactorPolicy extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'domaine_id',
        'enforced_at',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'domaine_id' => 'integer',
            'enforced_at' => 'datetime',
        ];
    }

    public function domaine(): BelongsTo
    {
        return $this->belongsTo(Domaine::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Politique 2FA d'un domaine (une ligne par domaine depuis la migration
     * 2026_10_16_000008). Défaut : `sis`, pour les appelants non encore
     * généralisés à un domaine explicite.
     * Ne pas cibler `firstOrCreate(['id' => 1])` : `id` n'étant pas
     * mass-assignable, la recherche ne retrouve jamais la ligne créée et une
     * nouvelle ligne est insérée à chaque appel.
     */
    public static function current(?int $domaineId = null): self
    {
        $domaineId ??= Domaine::where('code', 'sis')->value('id');

        return static::query()->where('domaine_id', $domaineId)->first()
            ?? static::create(['domaine_id' => $domaineId]);
    }

    /**
     * Le 2FA est-il obligatoire à l'instant présent pour ce domaine (défaut :
     * `sis`), indépendamment du toggle d'environnement
     * (config('gestsis.two_factor_enforcement_enabled')) ?
     */
    public static function isEnforced(?int $domaineId = null): bool
    {
        $policy = static::current($domaineId);

        return $policy->enforced_at !== null && now()->gte($policy->enforced_at);
    }
}
