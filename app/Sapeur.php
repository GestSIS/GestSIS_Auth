<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sapeur extends Model
{
    use HasFactory;

    protected $casts = ['sapeur_id' => 'integer', 'sis_id' => 'integer', 'user_id' => 'integer'];
}
