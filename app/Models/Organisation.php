<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Organisation extends Model
{
    protected $fillable = [
        'domaine_id',
        'nom',
        'org_key',
    ];

    protected function casts(): array
    {
        return ['domaine_id' => 'integer'];
    }

    public function domaine(): BelongsTo
    {
        return $this->belongsTo(Domaine::class);
    }
}
