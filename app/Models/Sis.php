<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sis extends Model
{
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
        return $this->hasMany(Role::class);
    }
}
