<?php

namespace App\Console\Commands;

use App\Auth\TokenTools;
use App\Console\Commands\Concerns\AnnouncesDryRunActions;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

#[Signature('users:sync-sapeurs {--dry-run : Affiche les actions sans écrire en base}')]
#[Description('Crée les liens sapeur/utilisateur manquants par correspondance d\'email, et signale les conflits pour investigation manuelle')]
class SyncSapeurUserMappings extends Command
{
    use AnnouncesDryRunActions;

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
        // Comparaison en mémoire, donc normalisée en minuscules pour rester cohérente
        // avec la collation MySQL insensible à la casse (utf8mb4_unicode_ci) utilisée
        // partout ailleurs (validateEmail, confirmation d'email) pour ce même matching.
        $usersByEmail = User::all()->keyBy(fn($u) => mb_strtolower($u->email));

        [$exactByTriple, $aliveByUserSis, $aliveBySapeurSis] = $this->indexExistingLinks();

        $stats = ['created' => 0, 'reactivated' => 0, 'conflicts' => 0];

        foreach ($emailsParSis as $sisKey => $emailsBySapeurId) {
            $sis = $sisByApiKey->get($sisKey);
            if ($sis === null) {
                Log::warning($this->getName() . ' - SIS inconnu localement', ['sis_key' => $sisKey]);
                continue;
            }

            foreach ($emailsBySapeurId as $sapeurId => $email) {
                $user = $usersByEmail->get(mb_strtolower($email));

                // Aucun compte GestSIS avec cet email : rien à synchroniser (cf. "Sapeurs sans comptes").
                if ($user === null) {
                    continue;
                }

                $outcome = $this->syncMapping(
                    $user,
                    $sis,
                    (int) $sapeurId,
                    $dryRun,
                    $exactByTriple,
                    $aliveByUserSis,
                    $aliveBySapeurSis,
                );

                if ($outcome !== null) {
                    $stats[$outcome]++;
                }
            }
        }

        $this->info("Liens créés : {$stats['created']}");
        $this->info("Liens réactivés : {$stats['reactivated']}");
        $this->info("Conflits détectés (reportés à Sentry) : {$stats['conflicts']}");

        return self::SUCCESS;
    }

    /**
     * Synchronise un couple (sapeur_id, email) pour un SIS donné :
     * - une correspondance exacte (même triplet user/sapeur/sis) déjà vivante ne fait rien ;
     * - une correspondance exacte déjà coupée est réactivée telle quelle (pas de doublon) ;
     * - un lien encore vivant d'une AUTRE identité (même user, autre sapeur, ou l'inverse)
     *   bloque et déclenche un vrai conflit ;
     * - sinon un nouveau lien est créé. Un lien mort d'une autre identité n'est jamais
     *   modifié ni supprimé : il reste en base comme historique.
     *
     * @return 'created'|'reactivated'|'conflicts'|null null si déjà synchronisé (rien à faire)
     */
    private function syncMapping(
        User $user,
        Sis $sis,
        int $sapeurId,
        bool $dryRun,
        Collection $exactByTriple,
        Collection $aliveByUserSis,
        Collection $aliveBySapeurSis,
    ): ?string {
        $exact = $exactByTriple->get("{$user->id}:{$sapeurId}:{$sis->id}");

        if ($exact !== null) {
            if ($exact->deactivated_at === null) {
                return null; // déjà synchronisé et actif
            }

            $this->reactivateLink($exact, $user, $sis, $sapeurId, $dryRun);

            if (!$dryRun) {
                $aliveByUserSis->put("{$user->id}:{$sis->id}", $sapeurId);
                $aliveBySapeurSis->put("{$sapeurId}:{$sis->id}", $user->id);
            }

            return 'reactivated';
        }

        $blockingSapeurId = $aliveByUserSis->get("{$user->id}:{$sis->id}");
        $blockingUserId = $aliveBySapeurSis->get("{$sapeurId}:{$sis->id}");

        if ($blockingSapeurId !== null || $blockingUserId !== null) {
            $this->reportConflict($user, $sis, $sapeurId, $blockingSapeurId, $blockingUserId);
            return 'conflicts';
        }

        $this->createLink($user, $sis, $sapeurId, $dryRun);

        if (!$dryRun) {
            $aliveByUserSis->put("{$user->id}:{$sis->id}", $sapeurId);
            $aliveBySapeurSis->put("{$sapeurId}:{$sis->id}", $user->id);
        }

        return 'created';
    }

    private function reactivateLink(Sapeur $exact, User $user, Sis $sis, int $sapeurId, bool $dryRun): void
    {
        $this->announce('reactivate', "{$user->email} -> sis={$sis->nom} sapeur_id={$sapeurId}", $dryRun);

        if ($dryRun) {
            return;
        }

        Log::info($this->getName() . ' - lien réactivé', [
            'sapeur_link_id' => $exact->id,
            'user_id' => $user->id,
            'sis_id' => $sis->id,
            'sapeur_id' => $sapeurId,
        ]);
        Sapeur::where('id', $exact->id)->update([
            'deactivated_at' => null,
            'pending_deactivation_at' => null,
        ]);
    }

    private function createLink(User $user, Sis $sis, int $sapeurId, bool $dryRun): void
    {
        $this->announce('link', "{$user->email} -> sis={$sis->nom} sapeur_id={$sapeurId}", $dryRun);

        if ($dryRun) {
            return;
        }

        Sapeur::insert([
            'sapeur_id' => $sapeurId,
            'sis_id' => $sis->id,
            'user_id' => $user->id,
        ]);
    }

    private function reportConflict(User $user, Sis $sis, int $sapeurId, ?int $blockingSapeurId, ?int $blockingUserId): void
    {
        $details = [];
        if ($blockingSapeurId !== null) {
            $details[] = "le compte a déjà sapeur_id={$blockingSapeurId} actif pour ce SIS";
        }
        if ($blockingUserId !== null) {
            $details[] = "sapeur_id={$sapeurId} est déjà lié activement à user_id={$blockingUserId}";
        }

        report(new RuntimeException(
            $this->getName() . " - conflit de mapping sapeur/utilisateur : user_id={$user->id} ({$user->email}), "
            . "sis_id={$sis->id} ({$sis->nom}), sapeur_id={$sapeurId} : " . implode(' ; ', $details)
            . '. Vérification manuelle nécessaire (activation/désactivation/suppression de sapeur, ou changement d\'email).'
        ));
    }

    /**
     * Indexe les liens existants pour un lookup en mémoire sans requête par entrée :
     * - la correspondance exacte (triplet user/sapeur/sis, peu importe l'état) sert à
     *   détecter une réactivation possible ;
     * - les index "vivant par user/sis" et "vivant par sapeur/sis" (liens morts exclus)
     *   servent à détecter un vrai conflit avec une autre identité.
     *
     * @return array{0: Collection<string, Sapeur>, 1: Collection<string, int>, 2: Collection<string, int>}
     */
    private function indexExistingLinks(): array
    {
        $links = Sapeur::all();
        [$aliveByUserSis, $aliveBySapeurSis] = Sapeur::indexAliveByIdentity($links->whereNull('deactivated_at'));

        return [
            $links->keyBy(fn($l) => "{$l->user_id}:{$l->sapeur_id}:{$l->sis_id}"),
            $aliveByUserSis,
            $aliveBySapeurSis,
        ];
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
                $this->getName() . " - échec de récupération des emails de sapeurs (status {$response->status()})"
            ));
            Log::error($this->getName() . ' - échec de récupération des emails de sapeurs', [
                'status' => $response->status(),
            ]);
            return null;
        }

        return $response->json('data', []);
    }
}
