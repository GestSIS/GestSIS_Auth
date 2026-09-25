<?php

namespace App\Console\Commands;

use App\Mail\TwoFactorReminderMail;
use App\Models\TwoFactorPolicy;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('users:2fa-reminder')]
#[Description("Envoie un rappel par email aux comptes actifs sans 2FA à J-30/J-7/J-1 de l'échéance d'enforcement")]
class SendTwoFactorReminders extends Command
{
    // Du plus urgent au moins urgent : on ne veut envoyer qu'un seul rappel par
    // exécution, celui correspondant au seuil le plus proche déjà atteint.
    private const REMINDER_THRESHOLDS_DAYS = [1, 7, 30];

    /**
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
        $policy = TwoFactorPolicy::current();
        if ($policy->enforced_at === null) {
            $this->info('Aucune politique 2FA active, rien à faire.');
            return self::SUCCESS;
        }
        $enforcedAt = Carbon::parse($policy->enforced_at);

        $sent = 0;
        $failed = 0;
        $candidates = User::whereNull('disabled_at')
            ->withoutTwoFactorEnabled()
            ->get();

        foreach ($candidates as $user) {
            if ($user->isTwoFactorExempt()) {
                continue;
            }

            $threshold = $this->mostUrgentReachedThreshold($enforcedAt);
            if ($threshold === null) {
                continue;
            }

            $reminderDate = $enforcedAt->copy()->subDays($threshold);
            if ($user->two_factor_reminder_sent_at !== null && Carbon::parse($user->two_factor_reminder_sent_at)->gte($reminderDate)) {
                continue;
            }

            // Un envoi en échec ne doit pas bloquer les suivants : la date de
            // rappel de ce compte n'est pas posée, il sera retenté au prochain passage.
            try {
                Mail::to($user->email)->send(new TwoFactorReminderMail($user, $enforcedAt));
            } catch (Throwable $e) {
                report($e);
                $failed++;
                continue;
            }
            $user->two_factor_reminder_sent_at = now();
            $user->save();
            $sent++;
        }

        $this->info("Rappels 2FA envoyés : {$sent}");
        if ($failed > 0) {
            $this->error("Rappels 2FA en échec : {$failed}");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function mostUrgentReachedThreshold(CarbonInterface $enforcedAt): ?int
    {
        foreach (self::REMINDER_THRESHOLDS_DAYS as $days) {
            if (now()->gte($enforcedAt->copy()->subDays($days))) {
                return $days;
            }
        }

        return null;
    }
}
