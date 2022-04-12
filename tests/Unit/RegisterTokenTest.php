<?php

namespace Tests\Unit;

use App\Auth\TokenTools;
use App\User;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class RegisterTokenTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function testCreateRegisterTokenWithValidPermissions()
    {
        $user = new User();
        $bearerToken = TokenTools::createAccessToken($user, ['test' => ['utilisateur.tout']], []);
        $params = [
            'roles' => [
                3
            ]
        ];

        $response = $this->withHeaders([
            'Sis-Id' => 'test',
            'Authorization' => 'Bearer ' . $bearerToken,
        ])->post("api/v1/register-token/", $params);

        $response->assertStatus(200);
    }
}
