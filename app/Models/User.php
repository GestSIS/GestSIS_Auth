<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class User extends Authenticatable
{
    use Notifiable;
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'validate_email_token',
        'validate_email_expire'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'validate_email_token',
        'validate_email_expire'
    ];

    /**
     * Get the attributes that should be cast.
     * 
     * @return array
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'validate_email_expire' => 'datetime',
            'password' => 'hashed',
            'admin' => 'boolean',
            'pending_deactivation_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public static function getPermissions(int|string $userId): array
    {
        $user = User::find($userId);
        // Load permissions
        $permissions = DB::table('permissions')
            ->join('permission_roles', 'permissions.id', '=', 'permission_roles.permission_id')
            ->join('roles', 'roles.id', '=', 'permission_roles.role_id')
            ->join('user_roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('sis', 'sis.id', '=', 'roles.sis_id')
            ->where('user_roles.user_id', '=', $userId)
            ->select('permissions.api_key as perm_key', 'sis.api_key as sis_key')
            ->distinct()
            ->get();

        $groupedPermissions = [];
        foreach ($permissions as $element) {
            $groupedPermissions[$element->sis_key][] = $element->perm_key;
        }
        return $groupedPermissions;
    }

    /**
     * Recharge l'utilisateur depuis la DB et retourne null s'il n'existe plus
     * ou a été désactivé — pour ne pas se fier uniquement au JWT, qui reste
     * valide jusqu'à expiration naturelle même après désactivation du compte.
     */
    public static function findActive(int|string $userId): ?self
    {
        $user = self::find($userId);

        return ($user === null || $user->disabled_at !== null) ? null : $user;
    }

    /**
     * Summary of getSapeurs
     * @param int|string $userId
     * @return array<int, int>
     */
    public static function getSapeurs(int|string $userId): array
    {
        // Le claim `sapeurs` n'est émis que pour un compte dont l'email est vérifié :
        // un lien créé (ou hérité) avant vérification ne doit donner aucun accès.
        $sapeurs = DB::table('sapeurs')
            ->join('sis', 'sis.id', '=', 'sapeurs.sis_id')
            ->join('users', 'users.id', '=', 'sapeurs.user_id')
            ->where('sapeurs.user_id', '=', $userId)
            ->whereNotNull('users.email_verified_at')
            ->whereNull('sapeurs.deactivated_at')
            ->select('sis.api_key as sis_key', 'sapeurs.sapeur_id as sapeur_id')
            ->distinct()
            ->get();

        $indexedSapeurs = [];
        foreach ($sapeurs as $element) {
            $indexedSapeurs[$element->sis_key] = $element->sapeur_id;
        }
        return $indexedSapeurs;
    }

    public static function getMobile(int|string $userId)
    {
        $mobiles = DB::table('roles')
            ->join('user_roles', 'roles.id', '=', 'user_roles.role_id')
            ->join('sis', 'sis.id', '=', 'roles.sis_id')
            ->where('user_roles.user_id', '=', $userId)
            ->select('sis.api_key as sis_key', 'sis.mobile as mobile')
            ->distinct()
            ->get();

        $groupedMobile = [];
        foreach ($mobiles as $element) {
            if ($element->mobile) {
                $groupedMobile[$element->sis_key][] = $element->mobile;
            }
        }
        return array_keys($groupedMobile);
    }

    /**
     * refreshTokens
     * @return HasMany<RefreshToken,$this>
     */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /**
     * @return HasMany<UserRole,$this>
     */
    public function userRoles(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * @return ?RefreshToken
     */
    public function getActiveRefreshToken(): ?RefreshToken
    {
        return $this->refreshTokens()->where('expire', '>', Carbon::now())->first();
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function sapeur(): HasMany
    {
        return $this->hasMany(Sapeur::class);
    }

    public function PasswordResetTokens(): HasMany
    {
        return $this->hasMany(PasswordResetToken::class, 'user_id');
    }
}
