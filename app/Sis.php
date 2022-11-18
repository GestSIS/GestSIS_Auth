<?php

namespace App;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sis extends Model
{
    use HasFactory;
    protected $table = 'sis';
    protected $fillable = [
        'nom', 'description', 'api_key', 'mobile'
    ];
    protected $casts = [
        'mobile' => 'boolean'
    ];
}
