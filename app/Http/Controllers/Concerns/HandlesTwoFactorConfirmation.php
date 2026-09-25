<?php

namespace App\Http\Controllers\Concerns;

use App\Auth\LoginResponder;
use App\Models\TwoFactorRecoveryCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * Partagé par TotpController et WebauthnController : la
 * confirmation d'une méthode 2FA a le même effet de bord et la même forme de
 * réponse quelle que soit la méthode confirmée (TOTP ou WebAuthn), et
 * l'ajout d'une méthode supplémentaire exige la même ré-authentification
 * quelle que soit la méthode ajoutée.
 */
trait HandlesTwoFactorConfirmation
{
    private const MAX_FAILED_ATTEMPTS = 5;

    private const FAILED_ATTEMPTS_LOCKOUT_SECONDS = 15 * 60;

    /**
     * Limite par compte des essais de mot de passe / code (en plus de la
     * limite par IP de la route). L'essai est compté AVANT la vérification :
     * sinon une rafale de requêtes parallèles verrait toutes un compteur à 0
     * pendant les vérifications bcrypt. À remettre à zéro par
     * RateLimiter::clear($key) en cas de succès.
     *
     * @return JsonResponse|null null si l'essai est autorisé, sinon la réponse 429.
     */
    private function countFailableAttempt(string $key): ?JsonResponse
    {
        if (RateLimiter::hit($key, self::FAILED_ATTEMPTS_LOCKOUT_SECONDS) <= self::MAX_FAILED_ATTEMPTS) {
            return null;
        }

        return response()->json([
            'message' => 'Trop de tentatives invalides. Réessayez dans ' . ceil(RateLimiter::availableIn($key) / 60) . ' minute(s).',
        ], 429);
    }

    private function stepUpAttemptsKey(User $user): string
    {
        return "2fa-step-up:{$user->id}";
    }

    /**
     * L'appelant a déjà posé son propre marqueur de confirmation (ex.
     * `users.two_factor_confirmed_at` pour TOTP ; la simple existence de la
     * ligne pour un credential WebAuthn) avant d'appeler cette méthode.
     *
     * @param bool $wasFirstMethod true si aucune méthode 2FA n'était encore
     *        confirmée avant celle-ci (à calculer par l'appelant avant de
     *        poser son propre marqueur) — émet les codes de secours
     *        uniquement dans ce cas : ils sont un filet de secours pour le
     *        compte, pas par méthode.
     */
    private function finalizeTwoFactorMethodConfirmation(Request $request, User $user, bool $wasFirstMethod): JsonResponse
    {
        $recoveryCodes = $wasFirstMethod ? TwoFactorRecoveryCode::regenerateFor($user) : null;

        // Premier moyen 2FA du compte : tout refresh token émis avant (donc
        // sans jamais avoir prouvé de second facteur) ne doit pas survivre à
        // l'activation — même logique que pour un changement de mot de passe
        // (ApiMotDePasseController::changer/reset). Fait avant l'éventuel
        // buildData() ci-dessous pour ne pas révoquer le tout nouveau jeton
        // qu'il émet.
        // Parcours volontaire : la session qui vient d'activer le 2FA est
        // conservée (sinon l'utilisateur serait déconnecté à son prochain
        // refresh), seules les autres sont révoquées.
        if ($wasFirstMethod) {
            $currentFamilyId = $request->attributes->get('session_family_id');
            $user->refreshTokens()
                ->when($currentFamilyId !== null, fn ($query) => $query->where(function ($query) use ($currentFamilyId) {
                    $query->where('family_id', '!=', $currentFamilyId)->orWhereNull('family_id');
                }))
                ->delete();
        }

        // Parcours forcé (politique d'enforcement dépassée) : l'utilisateur n'a
        // encore aucune session valide, cette confirmation doit donc terminer le
        // login. Parcours volontaire : une session valide existe déjà, inutile
        // d'en émettre une seconde.
        if ($request->attributes->get('two_factor_setup_flow', false)) {
            $data = LoginResponder::buildData($user, null, $request, $request->attributes->get('two_factor_remember', true));
            if ($recoveryCodes !== null) {
                $data['recoveryCodes'] = $recoveryCodes;
            }

            return response()->json(['data' => $data, 'message' => 'Successful login']);
        }

        return response()->json([
            'data' => $recoveryCodes !== null ? ['recoveryCodes' => $recoveryCodes] : [],
        ]);
    }

    /**
     * Ré-authentification exigée pour une action sensible (enrôlement d'une
     * méthode 2FA, régénération des codes de secours, suppression d'une clé) :
     * sans ça, un jeton de session volé (XSS, jeton qui traîne...) suffirait
     * à y attacher l'authentificateur d'un tiers — qui récupérerait les codes
     * de secours et fermerait toutes les sessions de la victime au premier
     * enrôlement. Seul le parcours forcé (setup token) en est dispensé : ce
     * jeton n'est émis par /login qu'après vérification du mot de passe.
     *
     * Si le TOTP est la méthode déjà active, exige aussi son code (peu
     * coûteux, défense supplémentaire). Si c'est WebAuthn, seul le mot de
     * passe est exigé : rejouer une cérémonie WebAuthn ici demanderait un
     * flow de "step-up" dédié, hors scope pour ce garde-fou.
     *
     * @return JsonResponse|null null si autorisé, sinon la réponse d'erreur à retourner telle quelle.
     */
    private function requireStepUpReauthentication(Request $request, User $user): ?JsonResponse
    {
        if ($request->attributes->get('two_factor_setup_flow', false)) {
            return null;
        }

        $validator = Validator::make($request->all(), ['password' => ['required', 'string']]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $attemptsKey = $this->stepUpAttemptsKey($user);
        if ($tooManyAttempts = $this->countFailableAttempt($attemptsKey)) {
            return $tooManyAttempts;
        }

        if (!Hash::check($request->input('password'), $user->password)) {
            return response()->json(['message' => 'Mot de passe incorrect'], 401);
        }

        if ($user->hasTotpConfirmed()) {
            $code = $request->input('code');
            if (!is_string($code) || !$this->verifyCodeOrRecovery($user, $code)) {
                return response()->json(['message' => 'Code invalide'], 422);
            }
        }

        RateLimiter::clear($attemptsKey);

        return null;
    }

    /**
     * Un secret TOTP en attente de confirmation (enrôlement commencé puis
     * abandonné) n'est jamais un second facteur valide : consumeTotpCode()
     * exige un TOTP confirmé. Un compte WebAuthn-only n'accepte donc ici que
     * ses codes de secours.
     */
    private function verifyCodeOrRecovery(User $user, string $code): bool
    {
        return $user->consumeTotpCode($code) || TwoFactorRecoveryCode::redeem($user, $code);
    }
}
