<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
