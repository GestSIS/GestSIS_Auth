<?php

namespace App\Console\Commands;

use App\Auth\TokenTools;
use App\Console\Commands\Concerns\AnnouncesDryRunActions;
use App\Mail\AccountPendingDeactivationMail;
use App\Mail\SapeurAccessPendingDeactivationMail;
use App\Models\ApiToken;
use App\Models\Role;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

#[Signature('users:process-deactivation {--dry-run : Affiche les actions sans écrire en base ni envoyer d\'email} {--no-notify : Marque les comptes/liens à désactiver sans envoyer les emails d\'avertissement (amorçage initial)}')]
#[Description('Retire immédiatement les rôles des sapeurs devenus inactifs, puis marque à désactiver/désactive les comptes sans rôle et sans sapeur actif lié')]
class ProcessAccountDeactivation extends Command
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
        $notify = !$this->option('no-notify');

        $actifsParSisId = $this->fetchSapeursActifsParSisId();
        if ($actifsParSisId === null) {
            $this->error("Impossible de récupérer la liste des sapeurs actifs depuis l'API, abandon.");
            return self::FAILURE;
        }

        $revokedRoles = $this->revokeStaleRoles($actifsParSisId, $dryRun);
        $this->info("Rôles retirés (sapeur inactif dans ce SIS) : {$revokedRoles}");

        $accountStats = $this->processAccounts($actifsParSisId, $dryRun, $notify);
        $this->info("Comptes marqués à désactiver : {$accountStats['flagged']}");
        $this->info("Comptes désactivés : {$accountStats['disabled']}");
        $this->info("Comptes réhabilités : {$accountStats['reinstated']}");
        $this->info("Comptes réactivés (après désactivation) : {$accountStats['reactivated']}");

        $linkStats = $this->processSapeurLinks($actifsParSisId, $dryRun, $notify);
        $this->info("Liens sapeur marqués à couper : {$linkStats['flagged']}");
        $this->info("Liens sapeur coupés : {$linkStats['cut']}");
        $this->info("Liens sapeur réhabilités : {$linkStats['reinstated']}");
        $this->info("Liens sapeur réactivés (après coupure) : {$linkStats['reactivated']}");

        return self::SUCCESS;
    }

    // Rôles

    /**
     * Retire immédiatement (sans délai de grâce ni email) les rôles d'un SIS
     * dès que le sapeur qui y est lié n'y est plus actif : contrairement au
     * compte ou au lien `sapeurs` (accès self-service), un rôle porte des
     * droits métier (validation, comptabilité, admin...) qui ne doivent pas
     * survivre à un oubli de retrait côté SIS. Seule une trace est loguée.
     *
     * @param array<int, array<int, int>> $actifsParSisId
     */
    private function revokeStaleRoles(array $actifsParSisId, bool $dryRun): int
    {
        $revoked = 0;
        $sisIdByRoleId = Role::pluck('sis_id', 'id');

        $users = User::with('sapeur', 'userRoles')->whereHas('userRoles')->get();

        foreach ($users as $user) {
            $sapeurBySisId = $user->sapeur->keyBy('sis_id');

            foreach ($user->userRoles as $userRole) {
                $sisId = $sisIdByRoleId[$userRole->role_id] ?? null;
                $link = $sisId !== null ? $sapeurBySisId->get($sisId) : null;

                // Pas de lien sapeur connu pour ce SIS : aucun signal fiable, on ne touche pas.
                if ($link === null) {
                    continue;
                }

                if (in_array($link->sapeur_id, $actifsParSisId[$sisId] ?? [], true)) {
                    continue;
                }

                $this->announce('revoke-role', "{$user->email} / sis_id={$sisId} / role_id={$userRole->role_id}", $dryRun);

                if ($dryRun) {
                    continue;
                }

                Log::info($this->getName() . ' - rôle retiré (sapeur inactif)', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'role_id' => $userRole->role_id,
                    'sis_id' => $sisId,
                ]);
                $userRole->delete();
                $revoked++;
            }
        }

        return $revoked;
    }

    // Comptes

    /**
     * @param array<int, array<int, int>> $actifsParSisId
     * @return array{flagged: int, disabled: int, reinstated: int, reactivated: int}
     */
    private function processAccounts(array $actifsParSisId, bool $dryRun, bool $notify): array
    {
        $flagged = 0;
        $disabled = 0;

        $candidates = User::with('sapeur')
            ->whereDoesntHave('userRoles')
            ->whereNull('disabled_at')
            ->where('admin', false)
            ->get();

        foreach ($candidates as $user) {
            if ($this->hasActiveSapeur($user, $actifsParSisId)) {
                continue;
            }

            if ($user->pending_deactivation_at === null) {
                $this->flagForDeactivation($user, $dryRun, $notify);
                $flagged++;
            } elseif ($user->pending_deactivation_at->isPast()) {
                $this->disableAccount($user, $dryRun);
                $disabled++;
            }
        }

        $reinstated = 0;

        $pending = User::with('sapeur', 'userRoles')
            ->whereNotNull('pending_deactivation_at')
            ->whereNull('disabled_at')
            ->get();

        foreach ($pending as $user) {
            if ($user->userRoles->isNotEmpty() || $this->hasActiveSapeur($user, $actifsParSisId)) {
                $this->reinstate($user, $dryRun);
                $reinstated++;
            }
        }

        $reactivated = $this->reactivateDisabledAccounts($actifsParSisId, $dryRun);

        return compact('flagged', 'disabled', 'reinstated', 'reactivated');
    }

    private function flagForDeactivation(User $user, bool $dryRun, bool $notify): void
    {
        $deactivateAt = $this->gracePeriodDeadline();
        $this->announce('flag', "{$user->email} -> désactivation prévue le {$deactivateAt->format('Y-m-d')}", $dryRun);

        if ($dryRun) {
            return;
        }

        $user->pending_deactivation_at = $deactivateAt;
        $user->save();

        if ($notify) {
            Mail::to($user->email)->send(new AccountPendingDeactivationMail($user));
        }
    }

    private function disableAccount(User $user, bool $dryRun): void
    {
        $this->announce('disable', $user->email, $dryRun);

        if ($dryRun) {
            return;
        }

        $user->disabled_at = now();
        $user->save();

        $user->refreshTokens()->delete();
        ApiToken::where('user_id', $user->id)->delete();
    }

    private function reinstate(User $user, bool $dryRun): void
    {
        $this->announce('reinstate', $user->email, $dryRun);

        if ($dryRun) {
            return;
        }

        $user->pending_deactivation_at = null;
        $user->save();
    }

    /**
     * Contrairement au stade "pending" (couvert par reinstate()), un compte déjà
     * désactivé (disabled_at posé) ne repassait jamais par ce pipeline : sans ce
     * passage, retrouver un rôle ou redevenir sapeur actif après désactivation
     * ne rouvrait jamais automatiquement l'accès.
     *
     * @param array<int, array<int, int>> $actifsParSisId
     */
    private function reactivateDisabledAccounts(array $actifsParSisId, bool $dryRun): int
    {
        $reactivated = 0;

        $disabledUsers = User::with('sapeur', 'userRoles')->whereNotNull('disabled_at')->get();

        foreach ($disabledUsers as $user) {
            if ($user->userRoles->isEmpty() && !$this->hasActiveSapeur($user, $actifsParSisId)) {
                continue;
            }

            $this->announce('reactivate', $user->email, $dryRun);

            if ($dryRun) {
                continue;
            }

            $user->disabled_at = null;
            $user->pending_deactivation_at = null;
            $user->save();
            $reactivated++;
        }

        return $reactivated;
    }

    // Liens sapeur

    /**
     * Coupe, par SIS, l'accès d'un sapeur qui n'y est plus actif (claim `sapeurs`
     * du JWT), indépendamment de l'état du compte lui-même : un sapeur peut
     * rester pleinement actif via un autre SIS.
     *
     * @param array<int, array<int, int>> $actifsParSisId
     * @return array{flagged: int, cut: int, reinstated: int, reactivated: int}
     */
    private function processSapeurLinks(array $actifsParSisId, bool $dryRun, bool $notify): array
    {
        $flagged = 0;
        $cut = 0;
        $reinstated = 0;

        $links = Sapeur::with('user.sapeur', 'sis')->whereNull('deactivated_at')->get();

        foreach ($links as $link) {
            $actif = in_array($link->sapeur_id, $actifsParSisId[$link->sis_id] ?? [], true);

            if ($actif) {
                if ($link->pending_deactivation_at !== null) {
                    $this->reinstateLink($link, $dryRun);
                    $reinstated++;
                }
                continue;
            }

            if ($link->pending_deactivation_at === null) {
                // Un départ complet (aucun sapeur actif nulle part) est déjà notifié
                // par l'email de désactivation de compte : pas de doublon ici.
                $shouldNotify = $notify && $this->hasActiveSapeur($link->user, $actifsParSisId);
                $this->flagLinkForDeactivation($link, $dryRun, $shouldNotify);
                $flagged++;
            } elseif ($link->pending_deactivation_at->isPast()) {
                $this->cutLinkAccess($link, $dryRun);
                $cut++;
            }
        }

        $reactivated = $this->reactivateCutLinks($links, $actifsParSisId, $dryRun);

        return compact('flagged', 'cut', 'reinstated', 'reactivated');
    }

    private function flagLinkForDeactivation(Sapeur $link, bool $dryRun, bool $notify): void
    {
        $deactivateAt = $this->gracePeriodDeadline();
        $this->announce('flag-sapeur', "{$link->user->email} / {$link->sis->nom} -> coupure prévue le {$deactivateAt->format('Y-m-d')}", $dryRun);

        if ($dryRun) {
            return;
        }

        $link->pending_deactivation_at = $deactivateAt;
        $link->save();

        if ($notify) {
            Mail::to($link->user->email)->send(new SapeurAccessPendingDeactivationMail($link->user, $link));
        }
    }

    private function cutLinkAccess(Sapeur $link, bool $dryRun): void
    {
        $this->announce('cut-sapeur', "{$link->user->email} / {$link->sis->nom}", $dryRun);

        if ($dryRun) {
            return;
        }

        $link->deactivated_at = now();
        $link->save();
    }

    private function reinstateLink(Sapeur $link, bool $dryRun): void
    {
        $this->announce('reinstate-sapeur', "{$link->user->email} / {$link->sis->nom}", $dryRun);

        if ($dryRun) {
            return;
        }

        $link->pending_deactivation_at = null;
        $link->save();
    }

    /**
     * Contrairement au stade "pending" (couvert par reinstateLink()), un lien déjà
     * coupé (deactivated_at posé) ne repassait jamais par ce pipeline : sans ce
     * passage, un sapeur redevenu actif après coupure ne récupérait jamais
     * automatiquement le SIS dans le claim `sapeurs` du JWT.
     *
     * @param Collection<int, Sapeur> $links Liens vivants déjà chargés par processSapeurLinks(),
     *        pour ne pas relancer la même requête ici.
     * @param array<int, array<int, int>> $actifsParSisId
     */
    private function reactivateCutLinks(Collection $links, array $actifsParSisId, bool $dryRun): int
    {
        $reactivated = 0;

        // Plusieurs lignes historiques peuvent exister pour un même user/sapeur
        // (aucune contrainte unique en base ne l'empêche plus, cf. migration
        // drop_unique_constraints_from_sapeurs_table) : avant de réactiver une
        // ligne coupée, vérifier qu'aucune autre ligne vivante n'occupe déjà
        // cette identité (ex. déjà recréée entre-temps par users:sync-sapeurs).
        [$aliveByUserSis, $aliveBySapeurSis] = Sapeur::indexAliveByIdentity($links->whereNull('deactivated_at'));

        $cutLinks = Sapeur::with('user', 'sis')->whereNotNull('deactivated_at')->get();

        foreach ($cutLinks as $link) {
            if (!in_array($link->sapeur_id, $actifsParSisId[$link->sis_id] ?? [], true)) {
                continue;
            }

            if ($aliveByUserSis->has("{$link->user_id}:{$link->sis_id}") || $aliveBySapeurSis->has("{$link->sapeur_id}:{$link->sis_id}")) {
                continue;
            }

            $this->announce('reactivate-sapeur', "{$link->user->email} / {$link->sis->nom}", $dryRun);

            if ($dryRun) {
                continue;
            }

            $link->deactivated_at = null;
            $link->pending_deactivation_at = null;
            $link->save();
            $reactivated++;

            $aliveByUserSis->put("{$link->user_id}:{$link->sis_id}", $link->sapeur_id);
            $aliveBySapeurSis->put("{$link->sapeur_id}:{$link->sis_id}", $link->user_id);
        }

        return $reactivated;
    }

    // Partagé

    /**
     * Récupère, pour chaque SIS (identifié par son id local), la liste des
     * sapeur_id actifs remontés par l'API. Retourne null en cas d'échec.
     *
     * @return ?array<int, array<int, int>>
     */
    private function fetchSapeursActifsParSisId(): ?array
    {
        $response = Http::withHeaders([
            'Sis-Key' => '_',
            'Authorization' => 'Bearer ' . TokenTools::createAccessToken(new User(), ['_' => ['admin']], [], []),
        ])->acceptJson()->timeout(10)->get(config('gestsis.api_url', '') . '/api/v2/sapeurs-actifs');

        if (!$response->successful()) {
            // Un échec de sync ici signifie que l'API est injoignable ou en erreur :
            // remonté à Sentry/Bugsink, pas seulement loggé.
            report(new RuntimeException(
                $this->getName() . " - échec de récupération des sapeurs actifs (status {$response->status()})"
            ));
            Log::error($this->getName() . ' - échec de récupération des sapeurs actifs', [
                'status' => $response->status(),
            ]);
            return null;
        }

        $sisByApiKey = Sis::all()->keyBy('api_key');
        $actifsParSisId = [];
        foreach ($response->json('data', []) as $sisKey => $sapeurIds) {
            if (!isset($sisByApiKey[$sisKey])) {
                Log::warning($this->getName() . ' - SIS inconnu localement', ['sis_key' => $sisKey]);
                continue;
            }
            $actifsParSisId[$sisByApiKey[$sisKey]->id] = $sapeurIds;
        }

        return $actifsParSisId;
    }

    /**
     * @param array<int, array<int, int>> $actifsParSisId
     */
    private function hasActiveSapeur(User $user, array $actifsParSisId): bool
    {
        foreach ($user->sapeur as $sapeurLink) {
            if (in_array($sapeurLink->sapeur_id, $actifsParSisId[$sapeurLink->sis_id] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    private function gracePeriodDeadline(): Carbon
    {
        return now()->addDays((int) config('gestsis.deactivation_grace_days', 30));
    }
}
