<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sis extends Model
{
    use HasFactory;

    protected $fillable = [
        'nom', 'description', 'api_key'
    ];
}
