<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileVersionControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('mobile-app:latest-version');
    }

    public function testReturnsTheLatestVersionFromTheGithubRelease(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response(['tag_name' => 'v2.6.10'], 200),
        ]);

        $response = $this->getJson('/api/v1/mobile/latest-version');

        $response->assertStatus(200);
        $response->assertJsonPath('data.version', '2.6.10');
    }

    public function testReturnsNullVersionWhenGithubRequestFails(): void
    {
        Http::fake([
            'api.github.com/*' => Http::response([], 404),
        ]);

        $response = $this->getJson('/api/v1/mobile/latest-version');

        $response->assertStatus(200);
        $response->assertJsonPath('data.version', null);
    }
}
