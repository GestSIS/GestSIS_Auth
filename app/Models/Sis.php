<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sis extends Model
{
    use HasFactory;

    protected $table = 'sis';
    protected $fillable = [
        'nom',
        'abreviation',
        'api_key',
        'mobile'
    ];

    protected function casts(): array
    {
        return ['mobile' => 'boolean'];
    }

    public function roles(): HasMany
    {
        // FK explicite : roles.organisation_id (plus roles.sis_id depuis le
        // renommage), organisations.id = sis.id pour tout SIS (SisObserver).
        return $this->hasMany(Role::class, 'organisation_id', 'id');
    }

    public function sapeurs(): HasMany
    {
        return $this->hasMany(Sapeur::class);
    }
}
