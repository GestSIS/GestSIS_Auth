<?php


namespace App\Auth;

use Carbon\Carbon;
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

    private const ISSUER = "GestSIS_Auth";
    private const AUDIENCE = "GestSIS_API";

    private const PRIVATE_KEY_FILE = "auth-private.key";
    private const PUBLIC_KEY_FILE = "auth-public.key";

    /**
     * @param $user
     * @return string
     * @throws FileNotFoundException
     */
    public static function createAccessToken($user, $permissions, $mobiles, $sapeurs)
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

    /**
     * @return Stdclass $token
     */
    public static function createRefreshToken()
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

    /**
     * @return Stdclass $token
     */
    public static function createConfirmationToken()
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

    /**
     * @return Stdclass $token
     */
    public static function createResetToken()
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
     * @param $token
     * @return object
     * @throw ExpiredException
     * @throws FileNotFoundException
     */
    public static function validateToken($token)
    {
        Log::debug("VALIDATE TOKEN");
        $publicKey = Storage::disk('keys')->get(self::PUBLIC_KEY_FILE);

        return JWT::decode($token, new Key($publicKey, 'RS256'));
    }
}
