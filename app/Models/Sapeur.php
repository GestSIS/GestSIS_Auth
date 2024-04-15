<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sapeur extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['sapeur_id' => 'integer', 'sis_id' => 'integer', 'user_id' => 'integer'];
    }
}
