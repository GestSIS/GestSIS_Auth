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
            'enforced_at' => 'datetime',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Réglage global unique de la politique 2FA (une seule ligne en base).
     * Ne pas cibler `firstOrCreate(['id' => 1])` : `id` n'étant pas
     * mass-assignable, la recherche ne retrouve jamais la ligne créée et une
     * nouvelle ligne est insérée à chaque appel.
     */
    public static function current(): self
    {
        return static::query()->first() ?? static::create([]);
    }

    /**
     * Le 2FA est-il obligatoire à l'instant présent, indépendamment du toggle
     * d'environnement (config('two_factor.enforcement_enabled')) ?
     */
    public function isEnforced(): bool
    {
        return $this->enforced_at !== null && now()->gte($this->enforced_at);
    }
}
