<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Sapeur extends Model
{
    protected function casts(): array
    {
        return [
            'sapeur_id' => 'integer',
            'organisation_id' => 'integer',
            'user_id' => 'integer',
            'pending_deactivation_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sis(): BelongsTo
    {
        // FK explicite : la colonne est renommée sis_id -> organisation_id
        // (Task 2) avant que cette relation elle-même ne soit renommée
        // sis() -> organisation() (Task 7) ; sans l'argument explicite,
        // l'inférence par défaut chercherait encore sis_id.
        return $this->belongsTo(Sis::class, 'organisation_id');
    }

    /**
     * Indexe une collection de liens vivants pour un lookup en mémoire sans
     * requête par entrée : la clé "user_id:sis_id" pointe vers le sapeur_id
     * qui occupe cette identité, et inversement pour "sapeur_id:sis_id".
     * Utilisé par SyncSapeurUserMappings et ProcessAccountDeactivation pour
     * détecter si une identité est déjà occupée par un lien actif, maintenant
     * que ce n'est plus garanti par une contrainte unique en base (cf.
     * migration drop_unique_constraints_from_sapeurs_table).
     *
     * @param Collection<int, self> $aliveLinks
     * @return array{0: Collection<string, int>, 1: Collection<string, int>}
     */
    public static function indexAliveByIdentity(Collection $aliveLinks): array
    {
        return [
            $aliveLinks->keyBy(fn(self $l) => "{$l->user_id}:{$l->organisation_id}")->map->sapeur_id,
            $aliveLinks->keyBy(fn(self $l) => "{$l->sapeur_id}:{$l->organisation_id}")->map->user_id,
        ];
    }
}
