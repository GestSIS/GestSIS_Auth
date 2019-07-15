<?php


namespace App\Auth;

use Exception;
use Firebase\JWT\ExpiredException;
use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;


class JwtTokenGuard implements Guard
{
    use GuardHelpers;
    private $request;

    public function __construct(UserProvider $provider, Request $request, $configuration)
    {
        Log::debug("Construct validator");
        $this->provider = $provider;
        $this->request = $request;
    }

    public function user()
    {
        Log::debug("User");
        if (!is_null($this->user)) {
            return $this->user;
        }
        $user = null;

        // retrieve token
        $token = $this->getTokenForRequest();

        if (!empty($token)) {
            try {
                $decoded = TokenTools::validateToken($token);
            } catch (ExpiredException $e) {
                dd("EXPIRED");
                return response()->json(["error" => "Token expired"]);
            } catch (Exception $e) {
                dd($e);
                return response()->json(["error" => "Invalid token"]);
            }

            // the token was found, how do you want to pass?
            $user = $this->provider->retrieveById($decoded->data->id);
        }
        Log::debug("Return user");
        return $this->user = $user;
    }

    /**
     * Get the token for the current request.
     * @return string
     */
    public function getTokenForRequest()
    {
        $token = $this->request->bearerToken();

        return $token;
    }

    /**
     * Validate a user's credentials.
     *
     * @param array $credentials
     *
     * @return bool
     */
    public function validate(array $credentials = [])
    {
        Log::debug("Validate");
        if (empty($credentials[$this->inputKey])) {
            return false;
        }
        $credentials = [$this->storageKey => $credentials[$this->inputKey]];
        if ($this->provider->retrieveByCredentials($credentials)) {
            return true;
        }
        return false;
    }
}
