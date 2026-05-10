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
    private const REFRESH_TOKEN_DURATION_IN_DAYS = 30;
    private const CONFIRMATION_TOKEN_DURATION_IN_DAYS = 30;

    private const REFRESH_TOKEN_LENGTH = 16;
    private const VALIDATION_TOKEN_LENGTH = 32;
    private const RESET_TOKEN_LENGTH = 32;
    private const API_TOKEN_LENGTH = 32;

    private const ISSUER = "GestSIS_Auth";
    private const AUDIENCE = "GestSIS_API";

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

    public static function createAccessToken(User $user, array $permissions, array $mobiles, array $sapeurs, ?bool $adminOverride = null): string
    {
        Log::debug("CREATE ACCESS TOKEN " . $user->name);

        $privateKey = Storage::disk('keys')->get(self::PRIVATE_KEY_FILE);

        $issuedat_claim = time(); // issued at
        $notbefore_claim = $issuedat_claim - 10; //not before in seconds
        $expire_claim = $issuedat_claim + self::ACCESS_TOKEN_DURATION_IN_HOURS * 3600; // expire time in seconds

        $token = array(
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
            ]
        );

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

        $token = array(
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
            ]
        );

        return JWT::encode($token, $privateKey, 'RS256');
    }

    public static function createRefreshToken(): Stdclass
    {
        Log::debug("CREATE REFRESH TOKEN");

        //Generate a random string.
        $token = Str::random(self::REFRESH_TOKEN_LENGTH);

        //Convert the binary data into hexadecimal representation.
        $refreshToken = new Stdclass();
        $refreshToken->token = bin2hex($token);
        $refreshToken->expire = Carbon::now()->addDays(self::REFRESH_TOKEN_DURATION_IN_DAYS);
        return $refreshToken;
    }

    public static function createConfirmationToken(): Stdclass
    {
        Log::debug("CREATE CONFIRMATION TOKEN");

        //Generate a random string.
        $token = Str::random(self::VALIDATION_TOKEN_LENGTH);

        //Convert the binary data into hexadecimal representation.
        $confirmToken = new Stdclass();
        $confirmToken->token = bin2hex($token);
        $confirmToken->expire = Carbon::now()->addDays(self::CONFIRMATION_TOKEN_DURATION_IN_DAYS);
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
        $resetToken->expire = Carbon::now()->addDays(self::RESET_TOKEN_DURATION_IN_HOURS);
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
     */
    public static function validateToken(string $token): stdClass
    {
        Log::debug("VALIDATE TOKEN");
        $publicKey = Storage::disk('keys')->get(self::PUBLIC_KEY_FILE);

        return JWT::decode($token, new Key($publicKey, 'RS256'));
    }
}
