<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ApiToken extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'token',
        'expires_at',
        'last_used_at',
        'revoked_at',
        'revoked_reason',
    ];

    /**
     * Raison de révocation : tous les jetons de l'utilisateur sont révoqués
     * lors d'une réinitialisation de mot de passe (chemin "mot de passe oublié"),
     * car ce chemin ne prouve que le contrôle de la boîte mail.
     */
    public const REASON_PASSWORD_RESET = 'password_reset';

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Révoque tous les jetons encore actifs d'un utilisateur et retourne leur nom,
     * pour que l'appelant puisse en informer l'utilisateur.
     *
     * @return list<string>
     */
    public static function revokeAllForUser(int $userId, string $reason): array
    {
        $tokens = self::where('user_id', $userId)->whereNull('revoked_at')->get();

        if ($tokens->isEmpty()) {
            return [];
        }

        self::whereIn('id', $tokens->pluck('id'))->update([
            'revoked_at' => now(),
            'revoked_reason' => $reason,
        ]);

        return $tokens->pluck('name')->values()->all();
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the API token.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the permissions assigned to this token.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'api_token_permissions');
    }

    /**
     * Get the SIS allowed for this token.
     * If empty, the token is valid for all user's SIS.
     */
    public function allowedSis(): BelongsToMany
    {
        return $this->belongsToMany(Sis::class, 'api_token_sis');
    }

    /**
     * Scope to only include active (non-expired) tokens.
     */
    public function scopeActive($query)
    {
        return $query->where('expires_at', '>', Carbon::now());
    }

    /**
     * Scope to filter tokens by user.
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Get valid permissions for this token.
     * Returns only permissions the user still has, grouped by SIS.
     * If the token has restricted SIS, only include those SIS.
     * 
     * @return array
     */
    public function getValidPermissions(): array
    {
        // Get token's assigned permissions
        $tokenPermissions = $this->permissions()->get();

        // Get user's current permissions (grouped by SIS)
        $userPermissions = User::getPermissions($this->user_id);

        // If token has restricted SIS, filter user permissions to only those SIS
        $allowedSisKeys = $this->allowedSis()->pluck('api_key')->toArray();
        if (!empty($allowedSisKeys)) {
            $userPermissions = collect($userPermissions)
                ->filter(fn($perms, $sisKey) => in_array($sisKey, $allowedSisKeys))
                ->toArray();
        }

        // Flatten user permissions for quick lookup
        $userPermissionKeys = collect($userPermissions)
            ->flatMap(fn($perms, $sisKey) => $perms)
            ->unique()
            ->toArray();

        // Filter and group token permissions by SIS
        return $tokenPermissions
            ->filter(fn($permission) => in_array($permission->api_key, $userPermissionKeys))
            ->reduce(function ($result, $permission) use ($userPermissions) {
                // Add this permission to every SIS in which the user holds it
                foreach ($userPermissions as $sisKey => $perms) {
                    if (in_array($permission->api_key, $perms)) {
                        $result[$sisKey][] = $permission->api_key;
                    }
                }
                return $result;
            }, []);
    }
}
