<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sapeur extends Model
{
    protected function casts(): array
    {
        return [
            'sapeur_id' => 'integer',
            'sis_id' => 'integer',
            'user_id' => 'integer',
            'pending_deactivation_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sis(): BelongsTo
    {
        return $this->belongsTo(Sis::class);
    }
}
