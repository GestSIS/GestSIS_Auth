<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    protected $fillable = [
        'domaine_id',
        'nom',
        'abbreviation',
        'org_key',
    ];

    protected function casts(): array
    {
        return ['domaine_id' => 'integer'];
    }

    public function domaine(): BelongsTo
    {
        return $this->belongsTo(Domaine::class);
    }

    /**
     * @return HasMany<Role,$this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    /**
     * @return HasMany<Sapeur,$this>
     */
    public function sapeurs(): HasMany
    {
        return $this->hasMany(Sapeur::class, 'sis_id', 'id');
    }
}
