<?php

namespace App\Services;

use App\Auth\TokenTools;
use App\Models\Domaine;
use App\Models\Organisation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ActiveIdentitiesFetcher
{
    /**
     * Récupère le roster des sapeurs actifs pour chaque domaine configuré,
     * en isolant l'échec d'un domaine du succès des autres.
     *
     * @return array{roster: Collection<int, Collection<int, ?string>>, failedDomaines: Collection<int, Domaine>}
     */
    public function fetch(): array
    {
        $roster = collect();
        $failedDomaines = collect();

        foreach (Domaine::whereNotNull('active_identities_url')->get() as $domaine) {
            try {
                $response = Http::withHeaders([
                    'Sis-Key' => '_',
                    'Authorization' => 'Bearer ' . TokenTools::createAccessToken(new User(), ['_' => ['admin']], [], [], type: TokenTools::TOKEN_TYPE_SERVICE),
                ])->acceptJson()->timeout(10)->get($domaine->active_identities_url);
            } catch (Throwable $e) {
                report($e);
                Log::error('ActiveIdentitiesFetcher - échec de récupération', ['domaine' => $domaine->code]);
                $failedDomaines->push($domaine);
                continue;
            }

            if (!$response->successful()) {
                report(new RuntimeException(
                    "ActiveIdentitiesFetcher - échec de récupération pour le domaine {$domaine->code} (status {$response->status()}, url {$domaine->active_identities_url})"
                ));
                Log::error('ActiveIdentitiesFetcher - échec de récupération', [
                    'domaine' => $domaine->code,
                    'status' => $response->status(),
                ]);
                $failedDomaines->push($domaine);
                continue;
            }

            $organisationsByKey = Organisation::where('domaine_id', $domaine->id)->get()->keyBy('org_key');

            foreach ($response->json('data', []) as $sisKey => $emailsBySapeurId) {
                $organisation = $organisationsByKey->get($sisKey);
                if ($organisation === null) {
                    Log::warning('ActiveIdentitiesFetcher - SIS inconnu localement', [
                        'domaine' => $domaine->code,
                        'sis_key' => $sisKey,
                    ]);
                    continue;
                }

                $roster->put($organisation->id, collect($emailsBySapeurId));
            }
        }

        return ['roster' => $roster, 'failedDomaines' => $failedDomaines];
    }
}
