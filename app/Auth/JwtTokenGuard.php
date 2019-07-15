<?php


namespace App\Auth;

use Illuminate\Auth\GuardHelpers;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use \Firebase\JWT\JWT;

class JwtTokenGuard implements Guard
{
    use GuardHelpers;
    private $inputKey = '';
    private $storageKey = '';
    private $request;

    public function __construct(UserProvider $provider, Request $request, $configuration)
    {
        $this->provider = $provider;
        $this->request = $request;
        // key to check in request
//        $this->inputKey = isset($configuration['input_key']) ? $configuration['input_key'] : 'access_token';

        // key to check in database
//        $this->storageKey = isset($configuration['storage_key']) ? $configuration['storage_key'] : 'access_token';
    }

    public function user()
    {
        if (!is_null($this->user)) {
            return $this->user;
        }
        $user = null;

        // retrieve via token
        $token = $this->getTokenForRequest();

        if (!empty($token)) {
            //TODO Valider le token

            $secret_key = "YOUR_SECRET_KEY";
            $decoded = JWT::decode($token, $secret_key, array('HS256'));
//
//            Log::debug((array)$decoded);
//            dd((array)$decoded);

            // the token was found, how do you want to pass?
            $user = $this->provider->retrieveById($decoded->data->id);
        }
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
