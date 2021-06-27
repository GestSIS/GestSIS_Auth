<?php

namespace Tests\Unit;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class RegisterTokenTest extends TestCase
{
    /**
     * A basic test example.
     *
     * @return void
     */
    public function testCreateRegisterTokenWithValidPermissions()
    {
        $bearerToken = null;
        $params = [
            'roles' => [
                1, 2, 
            ]
        ];

        //TODO: test
        // $response = $this->json('POST', "api/v1/register-token/create", $params);
    }
}
