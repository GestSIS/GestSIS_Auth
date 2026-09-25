<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TwoFactorRecoveryCode extends Model
{
    private const CODE_COUNT = 8;
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'code_hash',
        'used_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Régénère les codes de secours d'un utilisateur : supprime les anciens et
     * en crée 8 nouveaux. Retourne les codes en clair (uniquement affichables
     * une fois, jamais reconsultables ensuite — seul le hash est stocké).
     *
     * @return list<string>
     */
    public static function regenerateFor(User $user): array
    {
        static::where('user_id', $user->id)->delete();

        $plainCodes = [];
        $rows = [];
        $now = now();

        for ($i = 0; $i < self::CODE_COUNT; $i++) {
            $code = strtolower(Str::random(4) . '-' . Str::random(4));
            $plainCodes[] = $code;
            $rows[] = [
                'user_id' => $user->id,
                'code_hash' => Hash::make($code),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        static::insert($rows);

        return $plainCodes;
    }

    /**
     * Vérifie un code de secours et le marque comme consommé (usage unique).
     * Retourne true si un code valide et non utilisé a été trouvé. La
     * consommation est conditionnelle (`used_at` encore NULL) : de deux
     * requêtes concurrentes avec le même code, une seule l'emporte.
     */
    public static function redeem(User $user, string $plainCode): bool
    {
        $candidates = static::where('user_id', $user->id)->whereNull('used_at')->get();

        foreach ($candidates as $candidate) {
            if (Hash::check($plainCode, $candidate->code_hash)) {
                return static::whereKey($candidate->getKey())
                    ->whereNull('used_at')
                    ->update(['used_at' => now()]) === 1;
            }
        }

        return false;
    }
}
