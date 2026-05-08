<?php

namespace App\Console\Commands;

use App\Auth\TokenTools;
use App\Models\Sis;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('token:generate-long-term {sisKey} {--permission=intervention.lecture} {--duration=90} {--user-name=API-Token}')]
#[Description('Génère un token JWT de longue durée pour un SIS spécifique avec des permissions limitées')]
class GenerateLongTermToken extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $sisKey = $this->argument('sisKey');
        $permissionKey = $this->option('permission');
        $durationInDays = (int) $this->option('duration');
        $userName = $this->option('user-name');

        // Valider le SIS
        $sis = Sis::where('api_key', $sisKey)->first();
        if (!$sis) {
            $this->error("SIS avec l'api_key '{$sisKey}' introuvable.");
            $this->info("SIS disponibles :");
            Sis::all()->each(function ($s) {
                $this->line("  - {$s->api_key} ({$s->nom})");
            });
            return self::FAILURE;
        }

        // Créer ou récupérer un utilisateur technique
        $user = User::firstOrCreate(
            ['email' => 'api-token@gestsis.local'],
            [
                'name' => $userName,
                'password' => bcrypt(bin2hex(random_bytes(32))),
                'admin' => false,
                'email_verified_at' => now(),
            ]
        );

        // Construire les permissions pour ce SIS uniquement
        $permissions = [
            $sisKey => [$permissionKey]
        ];

        // Générer le token
        $token = TokenTools::createCustomDurationToken(
            $user,
            $permissions,
            [], // pas de mobiles
            [],  // pas de sapeurs
            $durationInDays
        );

        // Afficher les informations
        $this->info("Token généré avec succès !");
        $this->newLine();
        $this->line("SIS: {$sis->nom} ({$sisKey})");
        $this->line("Permission: {$permissionKey}");
        $this->line("Durée: {$durationInDays} jours");
        $this->line("Expiration: " . now()->addDays($durationInDays)->format('Y-m-d H:i:s'));
        $this->newLine();
        $this->line("Token:");
        $this->line($token);
        $this->newLine();
        
        return self::SUCCESS;
    }
}
