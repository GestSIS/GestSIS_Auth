<?php

namespace App\Services;

use App\Auth\TokenTools;
use App\Models\Domaine;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class EmailIdentityMatcher
{
    /**
     * Vérifie si cet email correspond à un sapeur actif pour UN SEUL domaine --
     * utilisé pour la porte d'inscription, qui ne doit jamais être décidée par une
     * correspondance dans un domaine différent de celui de la requête.
     *
     * @return array{matched: bool, failed: bool}
     */
    public function matchInDomaine(Domaine $domaine, string $email): array
    {
        $result = $this->fetchFromDomaine($domaine, $email);

        if ($result['failed']) {
            return ['matched' => false, 'failed' => true];
        }

        return ['matched' => $result['response']->json('data', []) !== [], 'failed' => false];
    }

    /**
     * Vérifie si cet email correspond à un sapeur actif dans N'IMPORTE QUEL domaine
     * configuré -- utilisé pour la liaison post-confirmation, qui est additive et
     * n'est pas limitée au domaine d'inscription du compte.
     *
     * @return array{matches: Collection<int, int>, failedDomaines: Collection<int, Domaine>}
     */
    public function matchAcrossAllDomaines(string $email): array
    {
        $matches = collect();
        $failedDomaines = collect();

        foreach (Domaine::whereNotNull('email_validate_url')->get() as $domaine) {
            $result = $this->fetchFromDomaine($domaine, $email);

            if ($result['failed']) {
                $failedDomaines->push($domaine);
                continue;
            }

            $organisationsByKey = Organisation::where('domaine_id', $domaine->id)->get()->keyBy('org_key');

            foreach ($result['response']->json('data', []) as $sisKey => $sapeurId) {
                $organisation = $organisationsByKey->get($sisKey);
                if ($organisation === null) {
                    Log::warning('EmailIdentityMatcher - SIS inconnu localement', [
                        'domaine' => $domaine->code,
                        'sis_key' => $sisKey,
                    ]);
                    continue;
                }

                $matches->put($organisation->id, $sapeurId);
            }
        }

        return ['matches' => $matches, 'failedDomaines' => $failedDomaines];
    }

    /**
     * @return array{response: ?Response, failed: bool}
     */
    private function fetchFromDomaine(Domaine $domaine, string $email): array
    {
        try {
            $response = Http::withHeaders([
                'Sis-Key' => '_',
                'Authorization' => 'Bearer ' . TokenTools::createAccessToken(new User(), ['_' => ['admin']], [], [], type: TokenTools::TOKEN_TYPE_SERVICE),
            ])->acceptJson()->timeout(3)->get($domaine->email_validate_url, ['email' => $email]);
        } catch (Throwable $e) {
            report($e);
            return ['response' => null, 'failed' => true];
        }

        if (!$response->successful()) {
            report(new RuntimeException(
                "EmailIdentityMatcher - échec pour le domaine {$domaine->code} (status {$response->status()})"
            ));
            return ['response' => null, 'failed' => true];
        }

        return ['response' => $response, 'failed' => false];
    }
}
