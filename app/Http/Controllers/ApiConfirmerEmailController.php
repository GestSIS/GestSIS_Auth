<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;

class ApiConfirmerEmailController extends Controller
{

    /**
     * Handle a registration request for the application.
     */
    public function confirmerEmail(Request $request): JsonResponse
    {
        // TODO: Décider de quoi logger
        Log::debug("Call confirmation de l'email");

        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        // Hash the provided token before database lookup
        $providedToken = $request->input('token');
        $hashedToken = TokenTools::hashToken($providedToken);
        $user = User::where('validate_email_token', '=', $hashedToken)
            ->where('validate_email_expire', '>=', Carbon::now())
            ->first();

        if (is_null($user)) {
            Log::warning('Invalid or expired email confirmation token attempt', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Jeton de confirmation invalide, expiré ou déjà utilisé.'], 401);
        }

        // Validation du compte
        $user->validate_email_token = null;
        $user->validate_email_expire = null;
        $user->email_verified_at = Carbon::now();
        $user->save();

        Log::info('Email confirmed successfully', [
            'user_id' => $user->id,
            'ip' => $request->ip(),
        ]);

        // Ajout des liaisons avec sapeur
        $response = Http::withHeaders([
            'Sis-Key' => '_',
            'Authorization' => 'Bearer ' . TokenTools::createAccessToken(new User(), ['_' => ['admin']], [], [])
        ])->acceptJson()->timeout(3)->get(config('gestsis.api_url', '') . '/api/v2/email-validate', ['email' => $user->email]); //->throw()->json();

        if ($response->successful() && $response['data']) {
            // Chargement de la liste des SIS
            $sis = Sis::all()->keyBy('api_key');

            // Ajout des liaisons avec Sapeur
            $sapeurs = [];
            foreach ($response['data'] as $sisKey => $sapeurId) {
                array_push($sapeurs, ['sapeur_id' => $sapeurId, 'sis_id' => $sis[$sisKey]->id, 'user_id' => $user->id]);
            }
            Sapeur::insert($sapeurs);
        }

        $permissions = User::getPermissions($user->id);
        $mobiles = User::getMobile($user->id);
        $sapeurs = User::getSapeurs($user->id);
        $accessToken = TokenTools::createAccessToken($user, $permissions, $mobiles, $sapeurs);

        return response()->json(
            array(
                "message" => "Email validé",
                "accessToken" => $accessToken,
            )
        );
    }

    /**
     * Get a validator for an incoming registration request.
     */
    protected function validator(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'token' => ['required', 'string', 'min:8'],
        ]);
    }
}
