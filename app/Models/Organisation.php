<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organisation extends Model
{
    use HasFactory;

    protected $fillable = [
        'domaine_id',
        'nom',
        'abreviation',
        'org_key',
        'mobile'
    ];

    protected function casts(): array
    {
        return ['domaine_id' => 'integer', 'mobile' => 'boolean'];
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
        return $this->hasMany(Sapeur::class, 'organisation_id', 'id');
    }
}
