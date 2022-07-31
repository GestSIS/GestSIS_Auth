<?php

namespace App\Http\Controllers;

use App\Auth\TokenTools;
use App\Mail\ConfirmationEmail;
use App\RefreshToken;
use App\RegisterToken;
use App\RegisterTokenRole;
use App\Sapeur;
use App\Sis;
use App\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class ApiConfirmerEmailController extends Controller
{

    /**
     * Handle a registration request for the application.
     *
     * @param Request $request
     * @return Response
     */
    public function confirmerEmail(Request $request)
    {
        // TODO: Décider de quoi logger
        Log::debug("Call confirmation de l'email");

        $validation = $this->validator($request->all());

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 401);
        }

        // Chargement de l'utilisateur
        $token = $request->get('token');
        $user = User::where('validate_email_token', '=', $token)->first();

        if (is_null($user)) {
            return response()->json(['error' => 'Jeton de confirmation invalide ou déjà utilisé.'], 401);
        }

        // Validation du compte
        $user->validate_email_token = null;
        $user->email_verified_at = Carbon::now();
        $user->save();

        // Ajout des liaisons avec sapeur
        $response = Http::withHeaders([
            'Sis-Id' => '_',
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
     *
     * @param array $data
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'token' => ['required', 'string', 'min:8'],
        ]);
    }
}
