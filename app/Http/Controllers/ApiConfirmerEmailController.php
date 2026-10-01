<?php

namespace App\Http\Controllers;

use App\Auth\LoginResponder;
use App\Auth\TokenTools;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ApiConfirmerEmailController extends Controller
{
    /**
     * Confirme l'email d'un compte fraîchement inscrit à l'aide du code reçu
     * par email, puis termine l'inscription : session complète, ou étape de
     * configuration 2FA si l'enforcement est actif (même décision qu'au
     * login, voir LoginResponder::respondAfterAuthentication) — aucune
     * session n'est émise avant que l'email ne soit prouvé.
     */
    public function confirmerEmail(Request $request): JsonResponse
    {
        // TODO: Décider de quoi logger
        Log::debug("Call confirmation de l'email");

        $this->validator($request->all())->validate();

        $user = User::where('email', $request->input('email'))
            ->whereNotNull('validate_email_token')
            ->where('validate_email_expire', '>=', Carbon::now())
            ->first();

        if ($user === null) {
            Log::warning('Invalid or expired email confirmation code attempt', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['message' => 'Code de confirmation invalide, expiré ou déjà utilisé.'], 401);
        }

        // 5 essais par code : limite `email-confirmation-failures` (route).
        // Pas de plafond par compte : il permettrait à un tiers de bloquer
        // l'activation, et la longueur du code rend inutile la boucle renvoi
        // + 5 essais.
        if (!$this->codeMatches(self::normalizeCode($request->input('code')), $user->validate_email_token)) {
            Log::warning('Invalid email confirmation code attempt', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Code de confirmation invalide, expiré ou déjà utilisé.'], 401);
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
            'Authorization' => 'Bearer ' . TokenTools::createAccessToken(new User(), ['_' => ['admin']], [], [], type: TokenTools::TOKEN_TYPE_SERVICE)
        ])->acceptJson()->timeout(3)->get(config('gestsis.api_url', '') . '/api/v2/email-validate', ['email' => $user->email]); //->throw()->json();

        if ($response->successful() && $response['data']) {
            // Chargement de la liste des SIS
            $sis = Sis::all()->keyBy('api_key');

            // Ajout des liaisons avec Sapeur
            $sapeurs = [];
            foreach ($response['data'] as $sisKey => $sapeurId) {
                // Ignore les SIS retournés par l'API mais inconnus localement
                if (!isset($sis[$sisKey])) {
                    Log::warning('SIS inconnu localement lors de la liaison sapeur', [
                        'sis_key' => $sisKey,
                        'user_id' => $user->id,
                    ]);
                    continue;
                }
                array_push($sapeurs, ['sapeur_id' => $sapeurId, 'sis_id' => $sis[$sisKey]->id, 'user_id' => $user->id]);
            }
            if (!empty($sapeurs)) {
                Sapeur::insert($sapeurs);
            }
        }

        return LoginResponder::respondAfterAuthentication($user, $request);
    }

    /**
     * Les comptes inscrits avant le passage au code saisi à la main ont encore
     * un jeton SHA-256 (lien de confirmation) : Hash::check lève une
     * exception sur un hash non-bcrypt, traité ici comme un code invalide —
     * l'utilisateur doit demander un nouveau code.
     */
    private function codeMatches(string $code, string $hash): bool
    {
        try {
            return Hash::check($code, $hash);
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Tolère la casse et les séparateurs saisis à la main (ex. "abcd-efgh").
     */
    private static function normalizeCode(string $code): string
    {
        return strtoupper(preg_replace('/[\s-]+/', '', $code));
    }

    /**
     * Get a validator for an incoming registration request.
     */
    protected function validator(array $data): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($data, [
            'email' => ['required', 'string', 'email'],
            'code' => ['required', 'string'],
        ]);
    }
}
