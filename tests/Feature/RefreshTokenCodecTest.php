<?php

namespace Tests\Feature;

use App\Auth\RefreshTokenCodec;
use App\Models\AuthSession;
use App\Models\User;
use RuntimeException;
use Tests\TestCase;

/**
 * Refresh tokens signés en HMAC-SHA256 : seul Auth peut en produire un valide.
 */
class RefreshTokenCodecTest extends TestCase
{
    private const KEY_1 = 'k1:base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=';
    private const KEY_2 = 'k2:base64:ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8=';

    private function makeSession(): AuthSession
    {
        return AuthSession::factory()->create(['user_id' => User::factory()->create()->id, 'refresh_generation' => 3]);
    }

    private function codec(string $keys): RefreshTokenCodec
    {
        config(['gestsis.refresh_token_hmac_keys' => $keys]);

        return new RefreshTokenCodec();
    }

    public function testAnEncodedTokenDecodesToItsSessionAndGeneration(): void
    {
        $session = $this->makeSession();
        $token = $this->codec(self::KEY_1)->encode($session, now()->addDay());

        $claims = $this->codec(self::KEY_1)->decode($token);

        $this->assertSame($session->id, $claims['sid']);
        $this->assertSame(3, $claims['gen']);
    }

    public function testATamperedPayloadIsRejected(): void
    {
        $token = $this->codec(self::KEY_1)->encode($this->makeSession(), now()->addDay());
        [$payload, $signature] = explode('.', $token);
        $content = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $content['gen'] = 99;
        $tampered = rtrim(strtr(base64_encode(json_encode($content)), '+/', '-_'), '=') . '.' . $signature;

        $this->assertNull($this->codec(self::KEY_1)->decode($tampered));
    }

    public function testATokenSignedWithAnotherKeyIsRejected(): void
    {
        $token = $this->codec(self::KEY_1)->encode($this->makeSession(), now()->addDay());

        // Même id de clé, autre valeur : signature invalide.
        $this->assertNull($this->codec('k1:base64:ICEiIyQlJicoKSorLC0uLzAxMjM0NTY3ODk6Ozw9Pj8=')->decode($token));
        // Clé retirée de la liste : jeton refusé.
        $this->assertNull($this->codec(self::KEY_2)->decode($token));
    }

    /**
     * Rotation planifiée : la nouvelle clé signe, l'ancienne est encore
     * acceptée en vérification jusqu'à son retrait.
     */
    public function testKeyRotationKeepsTokensSignedWithThePreviousKeyValid(): void
    {
        $session = $this->makeSession();
        $oldToken = $this->codec(self::KEY_1)->encode($session, now()->addDay());

        $rotated = $this->codec(self::KEY_2 . ',' . self::KEY_1);
        $newToken = $rotated->encode($session, now()->addDay());

        $this->assertNotNull($rotated->decode($oldToken));
        $this->assertNotNull($rotated->decode($newToken));
        $this->assertNull($this->codec(self::KEY_1)->decode($newToken));
    }

    public function testMalformedTokensAreRejected(): void
    {
        $codec = $this->codec(self::KEY_1);

        foreach (['', 'sans-point', 'a.b.c', '!!!.???', '.'] as $token) {
            $this->assertNull($codec->decode($token), "« {$token} » devrait être refusé");
        }
    }

    public function testAMissingOrWeakKeyConfigurationFailsLoudly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->codec('')->encode($this->makeSession(), now()->addDay());
    }

    public function testAKeyShorterThan32BytesIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->codec('k1:base64:' . base64_encode('trop-courte'))->encode($this->makeSession(), now()->addDay());
    }
}
