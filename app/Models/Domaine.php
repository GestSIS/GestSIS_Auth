<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Domaine extends Model
{
    protected $fillable = [
        'code',
        'nom',
    ];
}
