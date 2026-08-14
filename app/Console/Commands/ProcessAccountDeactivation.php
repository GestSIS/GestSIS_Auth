<?php

namespace App\Console\Commands;

use App\Auth\TokenTools;
use App\Mail\AccountPendingDeactivationMail;
use App\Mail\SapeurAccessPendingDeactivationMail;
use App\Models\ApiToken;
use App\Models\Sapeur;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

#[Signature('users:process-deactivation {--dry-run : Affiche les actions sans écrire en base ni envoyer d\'email} {--no-notify : Marque les comptes/liens à désactiver sans envoyer les emails d\'avertissement (amorçage initial)}')]
#[Description('Marque à désactiver, puis désactive, les comptes sans rôle et sans sapeur actif lié')]
class ProcessAccountDeactivation extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $notify = !$this->option('no-notify');

        $actifsParSisId = $this->fetchSapeursActifsParSisId();
        if ($actifsParSisId === null) {
            $this->error("Impossible de récupérer la liste des sapeurs actifs depuis l'API, abandon.");
            return self::FAILURE;
        }

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

        $this->info("Comptes marqués à désactiver : {$flagged}");
        $this->info("Comptes désactivés : {$disabled}");
        $this->info("Comptes réhabilités : {$reinstated}");

        $linkStats = $this->processSapeurLinks($actifsParSisId, $dryRun, $notify);
        $this->info("Liens sapeur marqués à couper : {$linkStats['flagged']}");
        $this->info("Liens sapeur coupés : {$linkStats['cut']}");
        $this->info("Liens sapeur réhabilités : {$linkStats['reinstated']}");

        return self::SUCCESS;
    }

    /**
     * Coupe, par SIS, l'accès d'un sapeur qui n'y est plus actif (claim `sapeurs`
     * du JWT), indépendamment de l'état du compte lui-même : un sapeur peut
     * rester pleinement actif via un autre SIS.
     *
     * @param array<int, array<int, int>> $actifsParSisId
     * @return array{flagged: int, cut: int, reinstated: int}
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

        return compact('flagged', 'cut', 'reinstated');
    }

    private function flagLinkForDeactivation(Sapeur $link, bool $dryRun, bool $notify): void
    {
        $deactivateAt = now()->addDays((int) config('gestsis.deactivation_grace_days', 30));
        $this->line("[flag-sapeur] {$link->user->email} / {$link->sis->nom} -> coupure prévue le {$deactivateAt->format('Y-m-d')}" . ($dryRun ? ' (dry-run)' : ''));

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
        $this->line("[cut-sapeur] {$link->user->email} / {$link->sis->nom}" . ($dryRun ? ' (dry-run)' : ''));

        if ($dryRun) {
            return;
        }

        $link->deactivated_at = now();
        $link->save();
    }

    private function reinstateLink(Sapeur $link, bool $dryRun): void
    {
        $this->line("[reinstate-sapeur] {$link->user->email} / {$link->sis->nom}" . ($dryRun ? ' (dry-run)' : ''));

        if ($dryRun) {
            return;
        }

        $link->pending_deactivation_at = null;
        $link->save();
    }

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
            Log::error('users:process-deactivation - échec de récupération des sapeurs actifs', [
                'status' => $response->status(),
            ]);
            return null;
        }

        $sisByApiKey = Sis::all()->keyBy('api_key');
        $actifsParSisId = [];
        foreach ($response->json('data', []) as $sisKey => $sapeurIds) {
            if (!isset($sisByApiKey[$sisKey])) {
                Log::warning('users:process-deactivation - SIS inconnu localement', ['sis_key' => $sisKey]);
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

    private function flagForDeactivation(User $user, bool $dryRun, bool $notify): void
    {
        $deactivateAt = now()->addDays((int) config('gestsis.deactivation_grace_days', 30));
        $this->line("[flag] {$user->email} -> désactivation prévue le {$deactivateAt->format('Y-m-d')}" . ($dryRun ? ' (dry-run)' : ''));

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
        $this->line("[disable] {$user->email}" . ($dryRun ? ' (dry-run)' : ''));

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
        $this->line("[reinstate] {$user->email}" . ($dryRun ? ' (dry-run)' : ''));

        if ($dryRun) {
            return;
        }

        $user->pending_deactivation_at = null;
        $user->save();
    }
}
