<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    protected $fillable = ['user_id', 'role_id'];

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'role_id' => 'integer'];
    }
}
