<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sapeur extends Model
{
    protected function casts(): array
    {
        return ['sapeur_id' => 'integer', 'sis_id' => 'integer', 'user_id' => 'integer'];
    }
}
