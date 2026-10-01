<?php

namespace App\Console\Commands;

use App\Models\AuthSession;
use App\Models\LegacyRefreshToken;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('sessions:prune')]
#[Description("Supprime les sessions terminées (inactivité ou durée maximale dépassée), et avec elles leur IP et navigateur")]
class PruneSessions extends Command
{
    public function handle(): int
    {
        try {
            $deleted = AuthSession::where('idle_expires_at', '<', now())
                ->orWhereHas('user', fn (Builder $user) => $user->whereRaw(
                    'DATE_ADD(auth_sessions.started_at, INTERVAL COALESCE(users.session_max_days, ?) DAY) < ?',
                    [AuthSession::DEFAULT_MAX_DAYS, now()],
                ))
                ->delete();

            $deletedLegacy = LegacyRefreshToken::where('expire', '<', now())->delete();
        } catch (Throwable $e) {
            report($e);
            $this->error("Erreur inattendue : {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->info("Sessions terminées supprimées : {$deleted}");
        $this->info("Anciens refresh tokens expirés supprimés : {$deletedLegacy}");

        return self::SUCCESS;
    }
}
