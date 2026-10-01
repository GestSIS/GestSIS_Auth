<?php

namespace Tests\Feature;

use App\Auth\WebauthnCeremony;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Régression : les objets `readonly` de web-auth/webauthn-lib ne supportent
 * pas serialize()/unserialize() natif de PHP. Les stocker tels quels dans le
 * cache "file" (utilisé en dev/prod, contrairement au cache "array" forcé en
 * test par phpunit.xml) les corrompait en __PHP_Incomplete_Class au retour,
 * faisant échouer toute vérification WebAuthn avec "Échec de la vérification
 * WebAuthn" — y compris avec un authenticator valide (ex. YubiKey). Le cache
 * doit donc transiter par WebauthnCeremony::serializeOptions()/deserialize*()
 * (JSON via le serializer de la lib), jamais par l'objet PHP brut.
 */
class WebauthnCeremonyCacheSerializationTest extends TestCase
{
    public function testCreationOptionsSurviveAFileCacheRoundTrip(): void
    {
        config(['cache.default' => 'file']);
        $ceremony = app(WebauthnCeremony::class);
        $user = User::factory()->create();

        $options = $ceremony->buildCreationOptions($user);
        Cache::store('file')->put('test-creation-options', $ceremony->serializeOptions($options));

        $roundTripped = $ceremony->deserializeCreationOptions(Cache::store('file')->pull('test-creation-options'));

        $this->assertSame($options->user->name, $roundTripped->user->name);
        $this->assertSame($options->rp->id, $roundTripped->rp->id);
        $this->assertSame(base64_encode($options->challenge), base64_encode($roundTripped->challenge));
    }

    public function testRequestOptionsSurviveAFileCacheRoundTrip(): void
    {
        config(['cache.default' => 'file']);
        $ceremony = app(WebauthnCeremony::class);

        $options = $ceremony->buildRequestOptions([]);
        Cache::store('file')->put('test-request-options', $ceremony->serializeOptions($options));

        $roundTripped = $ceremony->deserializeRequestOptions(Cache::store('file')->pull('test-request-options'));

        $this->assertSame($options->rpId, $roundTripped->rpId);
        $this->assertSame(base64_encode($options->challenge), base64_encode($roundTripped->challenge));
    }
}
