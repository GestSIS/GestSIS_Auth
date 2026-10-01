<?php

namespace App\Auth;

use App\Auth\Factors\TwoFactorException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

/**
 * Ré-authentification avant une action sensible (enrôlement ou retrait d'une
 * méthode 2FA, codes de secours, jeton d'API) : sans elle, un jeton de session
 * volé suffirait à attacher l'authentificateur d'un tiers ou à retirer la
 * protection. Le nombre d'échecs est limité par la route
 * (`throttle-failures:step-up-failures`).
 *
 * Exige le mot de passe, plus un code (TOTP ou de secours) si une application
 * TOTP est active. Seul le parcours forcé (setup token, émis par /login après
 * vérification du mot de passe) en est dispensé.
 */
class StepUpVerifier
{
    public function __construct(private readonly TwoFactorManager $twoFactor)
    {
    }

    /**
     * @throws TwoFactorException
     */
    public function ensure(Request $request, User $user): void
    {
        if ($request->attributes->get('two_factor_setup_flow', false)) {
            return;
        }

        $validator = Validator::make($request->all(), ['password' => ['required', 'string']]);
        if ($validator->fails()) {
            throw new TwoFactorException($validator->errors()->first(), 422);
        }

        if (!Hash::check($request->input('password'), $user->password)) {
            throw new TwoFactorException('Mot de passe incorrect', 401);
        }

        if ($this->twoFactor->totp()->isActiveFor($user)) {
            $code = $request->input('code');
            if (!is_string($code) || !$this->twoFactor->verifyCode($user, $code)) {
                throw new TwoFactorException('Code invalide', 422);
            }
        }
    }
}
