<?php

namespace App\Auth;

use App\Models\AuthSession;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Refresh token signé : `base64url(contenu JSON).base64url(HMAC-SHA256)`.
 *
 * Le contenu porte l'id de session, la génération, l'expiration et l'id de la
 * clé de signature. Seul Auth peut produire une signature valide : un jeton
 * correctement signé mais d'une génération dépassée a donc forcément été émis
 * par Auth (vol ou rejeu, la session est révoquée), alors qu'un jeton
 * fabriqué est simplement refusé — personne ne peut forcer la révocation
 * d'une session en inventant un jeton.
 */
class RefreshTokenCodec
{
    /**
     * Contexte signé avec le contenu : une signature produite pour un autre
     * usage ne peut jamais passer pour un refresh token.
     */
    private const CONTEXT = 'gestsis-auth/refresh-token/v1|';

    /**
     * @var array<string, string>|null id de clé => clé binaire, la première signe
     */
    private ?array $keys = null;

    public function encode(AuthSession $session, CarbonInterface $expiresAt): string
    {
        $keys = $this->keys();
        $keyId = array_key_first($keys);

        $payload = self::base64UrlEncode(json_encode([
            'sid' => $session->id,
            'gen' => $session->refresh_generation,
            'exp' => $expiresAt->getTimestamp(),
            'kid' => $keyId,
        ], JSON_THROW_ON_ERROR));

        return $payload . '.' . self::base64UrlEncode($this->sign($payload, $keys[$keyId]));
    }

    /**
     * @return array{sid: string, gen: int, exp: int}|null null si le jeton est
     *         mal formé, signé avec une clé inconnue ou modifié
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $signature] = $parts;

        $content = json_decode((string) self::base64UrlDecode($payload), true);
        if (!is_array($content) || !is_string($content['kid'] ?? null)) {
            return null;
        }

        $key = $this->keys()[$content['kid']] ?? null;
        if ($key === null || !hash_equals($this->sign($payload, $key), (string) self::base64UrlDecode($signature))) {
            return null;
        }

        if (!is_string($content['sid'] ?? null) || !is_int($content['gen'] ?? null) || !is_int($content['exp'] ?? null)) {
            return null;
        }

        return ['sid' => $content['sid'], 'gen' => $content['gen'], 'exp' => $content['exp']];
    }

    /**
     * Ancien format (avant les sessions signées) : jeton aléatoire sans point.
     */
    public static function isLegacyToken(string $token): bool
    {
        return !str_contains($token, '.');
    }

    private function sign(string $payload, string $key): string
    {
        return hash_hmac('sha256', self::CONTEXT . $payload, $key, true);
    }

    /**
     * @return array<string, string>
     */
    private function keys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $keys = [];
        foreach (array_filter(array_map('trim', explode(',', config('gestsis.refresh_token_hmac_keys')))) as $entry) {
            [$keyId, $encoded] = array_pad(explode(':', $entry, 2), 2, '');
            $key = base64_decode(str_starts_with($encoded, 'base64:') ? substr($encoded, 7) : $encoded, true);
            if ($keyId === '' || $key === false || strlen($key) < 32) {
                throw new RuntimeException("REFRESH_TOKEN_HMAC_KEYS : clé « {$keyId} » invalide (attendu id:base64:<32 octets>)");
            }
            $keys[$keyId] = $key;
        }

        if ($keys === []) {
            throw new RuntimeException('REFRESH_TOKEN_HMAC_KEYS est vide : impossible de signer les refresh tokens');
        }

        return $this->keys = $keys;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
