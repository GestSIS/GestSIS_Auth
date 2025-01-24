<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    protected $fillable = [
        'nom', 'description', 'api_key'
    ];

    protected function casts(): array
    {
        return ['sis_id' => 'integer'];
    }
}
