<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefreshToken extends Model
{
    protected $fillable = [
        'token',
        'expire',
        'family_id',
        'used_at',
        'last_used_at',
        'ip_address',
        'user_agent',
        'remember',
    ];

    protected function casts(): array
    {
        return [
            'expire' => 'datetime',
            'used_at' => 'datetime',
            'last_used_at' => 'datetime',
            'remember' => 'boolean',
        ];
    }

    /**
     * Révoque toute la session (famille de rotation) de ce jeton, pas seulement
     * cette ligne : un jeton déjà tourné ne doit pas laisser son successeur actif.
     */
    public function revokeFamily(): void
    {
        if ($this->family_id === null) {
            $this->delete();
            return;
        }

        self::where('user_id', $this->user_id)->where('family_id', $this->family_id)->delete();
    }

    /**
     * @return BelongsTo<User,$this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
