<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserRole extends Model
{
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'role_id' => 'integer'];
    }
}
