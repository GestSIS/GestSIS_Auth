<?php


namespace App\Auth;

use App\Models\User;
use Carbon\Carbon;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Stdclass;

class TokenTools
{
    private const RESET_TOKEN_DURATION_IN_HOURS = 1;
    private const ACCESS_TOKEN_DURATION_IN_HOURS = 8;
    private const REFRESH_TOKEN_NOT_REMEMBERED_DURATION_IN_DAYS = 1;
    private const REFRESH_TOKEN_REMEMBERED_DURATION_IN_DAYS = 30;
    private const EMAIL_CONFIRMATION_CODE_DURATION_IN_MINUTES = 30;
    private const EMAIL_CONFIRMATION_CODE_LENGTH = 8;
    private const EMAIL_CONFIRMATION_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const REFRESH_TOKEN_LENGTH = 16;
    private const RESET_TOKEN_LENGTH = 32;
    private const API_TOKEN_LENGTH = 32;

    private const ISSUER = "GestSIS_Auth";
    private const AUDIENCE = "GestSIS_API";

    // Audiences distinctes pour les jetons intermédiaires du flow 2FA : un jeton
    // avec cette audience est rejeté par validateToken() (donc par jwtTokenRole/
    // jwtTokenAdmin et par l'API/Alarm en aval), il ne peut servir qu'à l'endpoint
    // 2FA correspondant qui le décode explicitement via validateScopedToken().
    private const TWO_FACTOR_PRE_AUTH_AUDIENCE = "GestSIS_Auth_2FA";
    private const TWO_FACTOR_SETUP_AUDIENCE = "GestSIS_Auth_2FA_SETUP";

    private const TWO_FACTOR_TOKEN_DURATION_IN_MINUTES = 5;

    /**
     * Type d'un access token (claim `data.type`). Seules les sessions (vraie
     * connexion utilisateur) accèdent à la gestion du compte dans Auth :
     * voir JwtTokenValidatorRole. Les autres types restent utilisables par
     * l'API/Alarm, qui ignorent ce claim.
     */
    public const TOKEN_TYPE_SESSION = 'session';
    public const TOKEN_TYPE_API = 'api';
    public const TOKEN_TYPE_IMPERSONATION = 'impersonation';
    public const TOKEN_TYPE_SERVICE = 'service';

    private const PRIVATE_KEY_FILE = "auth-private.key";
    private const PUBLIC_KEY_FILE = "auth-public.key";

    /**
     * Hash a token for secure storage.
     * Uses SHA-256 for deterministic hashing (required for database lookups).
     * Following Laravel Sanctum's approach for personal access tokens.
     */
    public static function hashToken(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }

    /**
     * Compare a plain token with a hashed token in constant time.
     * Prevents timing attacks by using hash_equals().
     */
    public static function verifyToken(string $plainToken, string $hashedToken): bool
    {
        return hash_equals($hashedToken, self::hashToken($plainToken));
    }

    /**
     * @param string|null $sessionId family_id du refresh token de la session
     *        (claim `sid`) : permet d'identifier la session courante (liste des
     *        sessions, conservation de la session lors d'une révocation globale).
     * @param string $type Un des TOKEN_TYPE_* (claim `type`).
     */
    public static function createAccessToken(User $user, array $permissions, array $mobiles, array $sapeurs, ?bool $adminOverride = null, ?string $sessionId = null, string $type = self::TOKEN_TYPE_SESSION): string
    {
        Log::debug("CREATE ACCESS TOKEN " . $user->name);

        $privateKey = Storage::disk('keys')->get(self::PRIVATE_KEY_FILE);

        $issuedat_claim = time(); // issued at
        $notbefore_claim = $issuedat_claim - 10; //not before in seconds
        $expire_claim = $issuedat_claim + self::ACCESS_TOKEN_DURATION_IN_HOURS * 3600; // expire time in seconds

        $token = [
            "iss" => self::ISSUER,
            "aud" => self::AUDIENCE,
            "iat" => $issuedat_claim,
            "nbf" => $notbefore_claim,
            "exp" => $expire_claim,
            "data" => [
                "id" => $user->id,
                "admin" => $adminOverride ?? $user->admin,
                "validated" => $user->email_verified_at !== null,
                "pseudo" => $user->name,
                "email" => $user->email,
                "permissions" => $permissions,
                "mobiles" => $mobiles,
                "sapeurs" => $sapeurs,
                "sid" => $sessionId,
                "type" => $type,
            ]
        ];

        return JWT::encode($token, $privateKey, 'RS256');
    }

    /**
     * Create a custom duration access token.
     * 
     * @param User $user The user for whom the token is created
     * @param array $permissions Permissions grouped by SIS
     * @param array $mobiles Mobile numbers
     * @param array $sapeurs Sapeur IDs
     * @param int $durationInDays Duration of the token in days
     * @return string The JWT token
     */
    public static function createCustomDurationToken(User $user, array $permissions, array $mobiles, array $sapeurs, int $durationInDays): string
    {
        Log::debug("CREATE CUSTOM DURATION TOKEN " . $user->name . " - Duration: " . $durationInDays . " days");

        $privateKey = Storage::disk('keys')->get(self::PRIVATE_KEY_FILE);

        $issuedat_claim = time(); // issued at
        $notbefore_claim = $issuedat_claim - 10; //not before in seconds
        $expire_claim = $issuedat_claim + $durationInDays * 86400; // expire time in seconds (86400 = 24 hours)

        $token = [
            "iss" => self::ISSUER,
            "aud" => self::AUDIENCE,
            "iat" => $issuedat_claim,
            "nbf" => $notbefore_claim,
            "exp" => $expire_claim,
            "data" => [
                "id" => $user->id,
                "admin" => $user->admin,
                "validated" => $user->email_verified_at !== null,
                "pseudo" => $user->name,
                "email" => $user->email,
                "permissions" => $permissions,
                "mobiles" => $mobiles,
                "sapeurs" => $sapeurs,
                // Jeton d'intégration longue durée (token:generate-long-term) :
                // même restriction qu'un jeton d'API échangé via /token-auth.
                "type" => self::TOKEN_TYPE_API,
            ]
        ];

        return JWT::encode($token, $privateKey, 'RS256');
    }

    /**
     * Jeton issu d'un jeton d'API (ou d'un jeton longue durée d'intégration) :
     * ne doit jamais accéder aux réglages d'authentification du compte. Un
     * jeton émis avant l'ajout du claim `type` est considéré comme une session.
     */
    public static function isApiToken(stdClass $decodedToken): bool
    {
        return ($decodedToken->data->type ?? self::TOKEN_TYPE_SESSION) === self::TOKEN_TYPE_API;
    }

    /**
     * Jeton émis par un admin pour usurper un compte (admin/token) : peut
     * consulter les réglages d'authentification du compte, pas les modifier.
     */
    public static function isImpersonationToken(stdClass $decodedToken): bool
    {
        return ($decodedToken->data->type ?? null) === self::TOKEN_TYPE_IMPERSONATION;
    }

    /**
     * Jeton court, à portée volontairement restreinte, émis après validation du
     * mot de passe pour un compte ayant déjà activé le 2FA. N'autorise rien
     * d'autre que l'appel à `2fa/verify` : son audience distincte le fait
     * rejeter par validateToken() (donc par toute route jwtTokenRole/jwtTokenAdmin
     * et par l'API/Alarm en aval).
     */
    public static function createTwoFactorPreAuthToken(User $user, bool $remember = true): string
    {
        return self::encodeScopedToken($user, self::TWO_FACTOR_PRE_AUTH_AUDIENCE, $remember);
    }

    /**
     * Jeton équivalent, émis quand le compte n'a pas encore configuré le 2FA
     * mais que la politique d'enforcement l'exige désormais. N'autorise que
     * les appels à `2fa/enable` et `2fa/confirm`.
     */
    public static function createTwoFactorSetupToken(User $user, bool $remember = true): string
    {
        return self::encodeScopedToken($user, self::TWO_FACTOR_SETUP_AUDIENCE, $remember);
    }

    /**
     * `$remember` transite via ce jeton intermédiaire pour que le choix "se
     * souvenir de moi" fait à `/login` s'applique toujours à la session
     * complète émise une fois le 2FA validé (2fa/verify, webauthn/verify,
     * totp/confirm en parcours forcé).
     */
    private static function encodeScopedToken(User $user, string $audience, bool $remember = true): string
    {
        $privateKey = Storage::disk('keys')->get(self::PRIVATE_KEY_FILE);

        $issuedat_claim = time();
        $notbefore_claim = $issuedat_claim - 10;
        $expire_claim = $issuedat_claim + self::TWO_FACTOR_TOKEN_DURATION_IN_MINUTES * 60;

        $token = [
            "iss" => self::ISSUER,
            "aud" => $audience,
            "iat" => $issuedat_claim,
            "nbf" => $notbefore_claim,
            "exp" => $expire_claim,
            "data" => [
                "id" => $user->id,
                "remember" => $remember,
            ],
        ];

        return JWT::encode($token, $privateKey, 'RS256');
    }

    /**
     * @throws ExpiredException
     * @throws FileNotFoundException
     * @throws \UnexpectedValueException si l'audience ne correspond pas
     */
    public static function validateTwoFactorPreAuthToken(string $token): stdClass
    {
        return self::decodeAndAssertAudience($token, self::TWO_FACTOR_PRE_AUTH_AUDIENCE);
    }

    /**
     * @throws ExpiredException
     * @throws FileNotFoundException
     * @throws \UnexpectedValueException si l'audience ne correspond pas
     */
    public static function validateTwoFactorSetupToken(string $token): stdClass
    {
        return self::decodeAndAssertAudience($token, self::TWO_FACTOR_SETUP_AUDIENCE);
    }

    private static function decodeAndAssertAudience(string $token, string $expectedAudience): stdClass
    {
        $publicKey = Storage::disk('keys')->get(self::PUBLIC_KEY_FILE);
        $decoded = JWT::decode($token, new Key($publicKey, 'RS256'));

        if (($decoded->aud ?? null) !== $expectedAudience) {
            throw new \UnexpectedValueException('Audience non valide pour ce jeton');
        }

        return $decoded;
    }

    /**
     * @param string|null $familyId Réutilisé à travers les rotations d'un même
     *        login (voir ApiRefreshTokenController) ; laissé vide pour créer
     *        une nouvelle famille (un nouveau login).
     * @param bool $remember Détermine la durée de vie ("se souvenir de moi").
     */
    public static function createRefreshToken(?string $familyId = null, bool $remember = true): Stdclass
    {
        Log::debug("CREATE REFRESH TOKEN");

        //Generate a random string.
        $token = Str::random(self::REFRESH_TOKEN_LENGTH);

        $durationInDays = $remember
            ? self::REFRESH_TOKEN_REMEMBERED_DURATION_IN_DAYS
            : self::REFRESH_TOKEN_NOT_REMEMBERED_DURATION_IN_DAYS;

        //Convert the binary data into hexadecimal representation.
        $refreshToken = new Stdclass();
        $refreshToken->token = bin2hex($token);
        $refreshToken->expire = Carbon::now()->addDays($durationInDays);
        $refreshToken->familyId = $familyId ?? (string) Str::uuid();
        $refreshToken->remember = $remember;
        return $refreshToken;
    }

    /**
     * Code de 8 caractères envoyé par email et saisi directement dans le flow
     * d'inscription/renvoi — remplace l'ancien lien à jeton long (32 chars,
     * valable 30 jours) : plus cohérent avec la saisie de code déjà utilisée
     * pour le 2FA, et évite qu'un scanner de sécurité d'entreprise consomme
     * un lien à usage unique en l'ouvrant automatiquement avant l'utilisateur.
     */
    public static function createEmailConfirmationCode(): Stdclass
    {
        Log::debug("CREATE EMAIL CONFIRMATION CODE");

        // 8 caractères parmi 31 (≈ 8,5·10¹¹ combinaisons) : avec 5 essais par
        // code, deviner reste impraticable même en redemandant des codes en
        // boucle, sans plafond par compte (qui permettrait à un tiers de
        // bloquer l'activation). Alphabet sans caractères ambigus (0/O, 1/I/L).
        $alphabet = self::EMAIL_CONFIRMATION_CODE_ALPHABET;
        $code = '';
        for ($i = 0; $i < self::EMAIL_CONFIRMATION_CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $confirmToken = new Stdclass();
        $confirmToken->token = $code;
        $confirmToken->expire = Carbon::now()->addMinutes(self::EMAIL_CONFIRMATION_CODE_DURATION_IN_MINUTES);
        return $confirmToken;
    }

    public static function createResetToken(): Stdclass
    {
        Log::debug("CREATE Reset TOKEN");

        //Generate a random string.
        $token = Str::random(self::RESET_TOKEN_LENGTH);

        //Convert the binary data into hexadecimal representation.
        $resetToken = new Stdclass();
        $resetToken->token = bin2hex($token);
        $resetToken->expire = Carbon::now()->addHours(self::RESET_TOKEN_DURATION_IN_HOURS);
        return $resetToken;
    }

    /**
     * Create an API token for personal access.
     * Similar to reset tokens but with configurable duration.
     * 
     * @param int $durationDays Number of days until the token expires
     * @return Stdclass Object with token (plain hex string) and expire (Carbon timestamp)
     */
    public static function createApiToken(int $durationDays): Stdclass
    {
        Log::debug("CREATE API TOKEN - Duration: {$durationDays} days");

        // Generate a random string (32 chars for sufficient entropy)
        $token = Str::random(self::API_TOKEN_LENGTH);

        // Convert the binary data into hexadecimal representation
        $apiToken = new Stdclass();
        $apiToken->token = bin2hex($token);
        $apiToken->expire = Carbon::now()->addDays($durationDays);
        
        return $apiToken;
    }

    /**
     * @throws ExpiredException
     * @throws FileNotFoundException
     * @throws \UnexpectedValueException si l'audience ne correspond pas à un
     *         accessToken complet (rejette notamment les jetons 2FA intermédiaires,
     *         volontairement émis avec une audience distincte)
     */
    public static function validateToken(string $token): stdClass
    {
        Log::debug("VALIDATE TOKEN");

        return self::decodeAndAssertAudience($token, self::AUDIENCE);
    }
}
