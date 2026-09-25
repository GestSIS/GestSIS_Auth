<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use OTPHP\TOTP;

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
        'validate_email_expire',
        'two_factor_secret',
        'two_factor_last_used_timestep',
        ...self::TWO_FACTOR_ADMIN_ATTRIBUTES,
    ];

    /**
     * État 2FA réservé au tableau de bord admin (exemptions, rappels) : masqué
     * par défaut — un responsable SIS listant ses utilisateurs n'a pas à voir
     * quels comptes sont exemptés ni pourquoi. Rendu visible explicitement par
     * les endpoints admin (makeVisible).
     */
    public const TWO_FACTOR_ADMIN_ATTRIBUTES = [
        'two_factor_reminder_sent_at',
        'two_factor_exempt',
        'two_factor_exempt_until',
        'two_factor_exempt_reason',
        'two_factor_exempt_by',
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
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_timestep' => 'integer',
            'two_factor_reminder_sent_at' => 'datetime',
            'two_factor_exempt' => 'boolean',
            'two_factor_exempt_until' => 'datetime',
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
     * @return HasMany<TwoFactorRecoveryCode,$this>
     */
    public function twoFactorRecoveryCodes(): HasMany
    {
        return $this->hasMany(TwoFactorRecoveryCode::class);
    }

    public function hasTotpConfirmed(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    /**
     * Vérifie un code TOTP contre le secret stocké et le consomme : un même
     * pas de temps (30 s) ne peut servir qu'une seule fois, y compris entre
     * deux requêtes concurrentes (mise à jour conditionnelle atomique).
     *
     * @param bool $allowUnconfirmed true uniquement pour la confirmation
     *        initiale (le secret vient d'être généré, pas encore confirmé).
     */
    public function consumeTotpCode(string $code, bool $allowUnconfirmed = false): bool
    {
        if ($this->two_factor_secret === null || (!$allowUnconfirmed && !$this->hasTotpConfirmed())) {
            return false;
        }

        $totp = TOTP::createFromSecret($this->two_factor_secret);
        $timestamp = time();
        if (!$totp->verify($code, $timestamp)) {
            return false;
        }

        $timestep = intdiv($timestamp, $totp->getPeriod());
        $consumed = self::whereKey($this->getKey())
            ->where(function (Builder $query) use ($timestep) {
                $query->whereNull('two_factor_last_used_timestep')
                    ->orWhere('two_factor_last_used_timestep', '<', $timestep);
            })
            ->update(['two_factor_last_used_timestep' => $timestep]);

        if ($consumed === 1) {
            $this->two_factor_last_used_timestep = $timestep;
            $this->syncOriginalAttribute('two_factor_last_used_timestep');
        }

        return $consumed === 1;
    }

    /**
     * Comptes ayant au moins une méthode 2FA active (TOTP confirmé ou clé
     * WebAuthn) — équivalent requête de hasTwoFactorEnabled().
     *
     * @param Builder<User> $query
     */
    public function scopeWithTwoFactorEnabled(Builder $query): void
    {
        $query->where(function (Builder $query) {
            $query->whereNotNull('two_factor_confirmed_at')
                ->orWhereHas('webauthnCredentials');
        });
    }

    /**
     * @param Builder<User> $query
     */
    public function scopeWithoutTwoFactorEnabled(Builder $query): void
    {
        $query->whereNull('two_factor_confirmed_at')
            ->whereDoesntHave('webauthnCredentials');
    }

    /**
     * Umbrella : au moins une méthode 2FA est active, quelle qu'elle soit.
     * Utilisé partout ailleurs que dans les endpoints TOTP eux-mêmes (login,
     * bandeau, dashboard admin, suppression de méthode...).
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->hasTotpConfirmed() || $this->webauthnCredentials()->exists();
    }

    /**
     * @return HasMany<WebauthnCredential,$this>
     */
    public function webauthnCredentials(): HasMany
    {
        return $this->hasMany(WebauthnCredential::class);
    }

    /**
     * @return list<string>
     */
    public function twoFactorAvailableMethods(): array
    {
        $methods = [];
        if ($this->hasTotpConfirmed()) {
            $methods[] = 'totp';
        }
        if ($this->webauthnCredentials()->exists()) {
            $methods[] = 'webauthn';
        }

        return $methods;
    }

    /**
     * Exemption 2FA valide à l'instant présent (accordée par un admin,
     * potentiellement bornée dans le temps via two_factor_exempt_until).
     */
    public function isTwoFactorExempt(): bool
    {
        if (!$this->two_factor_exempt) {
            return false;
        }

        return $this->two_factor_exempt_until === null || now()->lt($this->two_factor_exempt_until);
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
