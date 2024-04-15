<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom', 'description', 'api_key'
    ];

    protected function casts(): array
    {
        return  ['sis_id' => 'integer'];
    }
}
