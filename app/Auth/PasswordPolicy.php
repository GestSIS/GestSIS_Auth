<?php

namespace App\Auth;

use Illuminate\Validation\Rules\Password;

/**
 * Règle de mot de passe commune à l'inscription, la réinitialisation et le
 * changement de mot de passe, avec ses messages en français (la locale de
 * l'application est `en` : sans eux, le front afficherait les messages
 * Laravel en anglais).
 *
 * `uncompromised()` interroge Have I Been Pwned par k-anonymat (seuls les
 * 5 premiers caractères du SHA-1 sont envoyés) ; si le service est
 * injoignable, Laravel laisse passer le mot de passe.
 */
class PasswordPolicy
{
    public static function rule(): Password
    {
        return Password::min(12)
            ->letters()
            ->uncompromised();
    }

    /**
     * @return array<string, string>
     */
    public static function messages(string $attribute): array
    {
        return [
            "{$attribute}.required" => 'Le mot de passe est obligatoire.',
            "{$attribute}.min" => 'Le mot de passe doit contenir au moins :min caractères.',
            "{$attribute}.password.letters" => 'Le mot de passe doit contenir au moins une lettre.',
            "{$attribute}.password.uncompromised" => 'Ce mot de passe apparaît dans une fuite de données connue, veuillez en choisir un autre.',
            "{$attribute}.confirmed" => 'La confirmation du mot de passe ne correspond pas.',
        ];
    }
}
