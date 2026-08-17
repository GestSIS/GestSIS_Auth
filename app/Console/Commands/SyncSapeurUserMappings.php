<?php

namespace App\Console\Commands;

use App\Auth\TokenTools;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

#[Signature('users:sync-sapeurs {--dry-run : Affiche les actions sans écrire en base}')]
#[Description('Crée les liens sapeur/utilisateur manquants par correspondance d\'email, et signale les conflits pour investigation manuelle')]
class SyncSapeurUserMappings extends Command
{
    /**
     * Execute the console command.
     *
     * Toute erreur inattendue est reportée à Sentry (via le handler
     * d'exceptions Laravel déjà branché sur Sentry/Bugsink dans
     * bootstrap/app.php) et se termine par un échec propre de la commande,
     * plutôt qu'un crash non géré.
     */
    public function handle(): int
    {
        try {
            return $this->process();
        } catch (Throwable $e) {
            report($e);
            $this->error("Erreur inattendue : {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    private function process(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $emailsParSis = $this->fetchSapeursEmailsParSis();
        if ($emailsParSis === null) {
            $this->error("Impossible de récupérer les emails de sapeurs depuis l'API, abandon.");
            return self::FAILURE;
        }

        $sisByApiKey = Sis::all()->keyBy('api_key');
        $usersByEmail = User::all()->keyBy('email');

        $links = Sapeur::all();
        $linkByUserSis = $links->keyBy(fn($l) => "{$l->user_id}:{$l->sis_id}");
        $linkBySapeurSis = $links->keyBy(fn($l) => "{$l->sapeur_id}:{$l->sis_id}");

        $created = 0;
        $conflicts = 0;

        foreach ($emailsParSis as $sisKey => $emailsBySapeurId) {
            $sis = $sisByApiKey->get($sisKey);
            if ($sis === null) {
                Log::warning('users:sync-sapeurs - SIS inconnu localement', ['sis_key' => $sisKey]);
                continue;
            }

            foreach ($emailsBySapeurId as $sapeurId => $email) {
                $sapeurId = (int) $sapeurId;
                $user = $usersByEmail->get($email);

                // Aucun compte GestSIS avec cet email : rien à synchroniser (cf. "Sapeurs sans comptes").
                if ($user === null) {
                    continue;
                }

                $existingByUser = $linkByUserSis->get("{$user->id}:{$sis->id}");
                $existingBySapeur = $linkBySapeurSis->get("{$sapeurId}:{$sis->id}");

                if ($existingByUser !== null && (int) $existingByUser->sapeur_id === $sapeurId) {
                    continue; // déjà synchronisé
                }

                if ($existingByUser !== null || $existingBySapeur !== null) {
                    $this->reportConflict($user, $sis, $sapeurId, $existingByUser, $existingBySapeur);
                    $conflicts++;
                    continue;
                }

                $this->line("[link] {$user->email} -> sis={$sis->nom} sapeur_id={$sapeurId}" . ($dryRun ? ' (dry-run)' : ''));

                if ($dryRun) {
                    continue;
                }

                Sapeur::insert([
                    'sapeur_id' => $sapeurId,
                    'sis_id' => $sis->id,
                    'user_id' => $user->id,
                ]);
                $created++;

                // Indispensable pour détecter un conflit si un second sapeur de ce
                // même run (ex. deux sapeurs partageant un email dans ce SIS) entre
                // en collision avec le lien qu'on vient tout juste de créer.
                $newLink = (object) ['user_id' => $user->id, 'sapeur_id' => $sapeurId, 'sis_id' => $sis->id];
                $linkByUserSis->put("{$user->id}:{$sis->id}", $newLink);
                $linkBySapeurSis->put("{$sapeurId}:{$sis->id}", $newLink);
            }
        }

        $this->info("Liens créés : {$created}");
        $this->info("Conflits détectés (reportés à Sentry) : {$conflicts}");

        return self::SUCCESS;
    }

    private function reportConflict(User $user, Sis $sis, int $sapeurId, ?object $existingByUser, ?object $existingBySapeur): void
    {
        $details = [];
        if ($existingByUser !== null) {
            $details[] = "le compte a déjà sapeur_id={$existingByUser->sapeur_id} pour ce SIS";
        }
        if ($existingBySapeur !== null) {
            $details[] = "sapeur_id={$sapeurId} est déjà lié à user_id={$existingBySapeur->user_id}";
        }

        report(new RuntimeException(
            "users:sync-sapeurs - conflit de mapping sapeur/utilisateur : user_id={$user->id} ({$user->email}), "
            . "sis_id={$sis->id} ({$sis->nom}), sapeur_id={$sapeurId} : " . implode(' ; ', $details)
            . '. Vérification manuelle nécessaire (activation/désactivation/suppression de sapeur, ou changement d\'email).'
        ));
    }

    /**
     * @return ?array<string, array<int, string>>
     */
    private function fetchSapeursEmailsParSis(): ?array
    {
        $response = Http::withHeaders([
            'Sis-Key' => '_',
            'Authorization' => 'Bearer ' . TokenTools::createAccessToken(new User(), ['_' => ['admin']], [], []),
        ])->acceptJson()->timeout(10)->get(config('gestsis.api_url', '') . '/api/v2/sapeurs-emails');

        if (!$response->successful()) {
            report(new RuntimeException(
                "users:sync-sapeurs - échec de récupération des emails de sapeurs (status {$response->status()})"
            ));
            Log::error('users:sync-sapeurs - échec de récupération des emails de sapeurs', [
                'status' => $response->status(),
            ]);
            return null;
        }

        return $response->json('data', []);
    }
}
